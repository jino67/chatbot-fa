<?php

namespace App\Ingestion\Catalog;

use App\Support\Currency;
use App\Support\Text;

/**
 * Prix et disponibilités tels que les commerçants les écrivent : « 18 000 », « 18.000 F CFA », « XOF 18000 »,
 * « à partir de 5000 », « 12,50 € », « 5000 - 8000 », « en stock », « 0 »... Tout devient une phrase lisible, avec la
 * devise de la cellule, sinon celle de la colonne ou de l'espace du client.
 */
final class Money
{
    /** @var array<string,string> */
    private const PATTERNS = [
        'XOF' => '/(?<!\p{L})(?:f\s?cfa|fcfa|cfa|xof|xaf)(?!\p{L})/iu',
        'EUR' => '/€|(?<!\p{L})(?:eur|euros?)(?!\p{L})/iu',
        'USD' => '/\$|(?<!\p{L})(?:usd|dollars?)(?!\p{L})/iu',
        'MAD' => '/(?<!\p{L})(?:dhs?|mad|dirhams?)(?!\p{L})/iu',
        'KMF' => '/(?<!\p{L})kmf(?!\p{L})/iu',
    ];

    /** Mots de trois lettres qui ne sont pas une devise. */
    private const NOT_CURRENCY = ['TTC', 'TVA', 'HTVA', 'PCS', 'KGS', 'LOT'];

    private const NUMBER = '/\d[\d .,\x{202F}]*\d|\d/u';

    /** Code de devise reconnu dans un texte (« FCFA » donne XOF), ou un code à trois lettres inconnu, ou null. */
    public static function currencyFrom(string $text): ?string
    {
        foreach (self::PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text)) {
                return $code;
            }
        }

        if (preg_match('/(?<!\p{L})([A-Z]{3})(?!\p{L})/u', $text, $m) && ! in_array($m[1], self::NOT_CURRENCY, true)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{display:?string, amount:?float, currency:?string} display null si la cellule est vide ;
     *                                                                  amount null si le texte n'est pas un montant (« sur devis »)
     */
    public static function parse(string $raw, string $default): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $raw)));
        if ($text === '') {
            return ['display' => null, 'amount' => null, 'currency' => null];
        }

        $currency = self::currencyFrom($text);
        $clean = $text;
        foreach (self::PATTERNS as $pattern) {
            $clean = preg_replace($pattern, ' ', $clean);
        }
        if ($currency !== null && ! isset(self::PATTERNS[$currency])) {
            $clean = preg_replace('/(?<!\p{L})'.preg_quote($currency, '/').'(?!\p{L})/u', ' ', $clean);
        }
        $clean = trim(preg_replace('/\s+/u', ' ', preg_replace('/(?<!\p{L})f(?!\p{L})/iu', ' ', $clean)));

        $prefix = '';
        if (preg_match('/^(?:à partir de|a partir de|dès|des|from)\s+/iu', $clean)) {
            $prefix = 'À partir de ';
            $clean = preg_replace('/^(?:à partir de|a partir de|dès|des|from)\s+/iu', '', $clean);
        }

        $code = $currency ?? $default;
        preg_match_all(self::NUMBER, $clean, $found);
        $numbers = $found[0];
        $middle = trim(preg_replace(self::NUMBER, ' ', $clean));
        // Les tirets longs (U+2013 et U+2014) s'écrivent en séquences : ils sont à reconnaître dans les fichiers des clients, pas à écrire.
        $rest = trim(preg_replace('/^[\s:\-\x{2013}\x{2014}\/.,]+|[\s:\-\x{2013}\x{2014}.,]+$/u', '', $middle));

        // Une fourchette : « 5000 - 8000 », « 5000 à 8000 ».
        if (count($numbers) === 2 && preg_match('/^(?:-|\x{2013}|\x{2014}|à|a|et|to)$/iu', $middle)) {
            $low = self::toFloat($numbers[0]);
            $high = self::toFloat($numbers[1]);
            if ($low !== null && $high !== null) {
                $symbol = self::symbol($code);

                return [
                    'display' => $prefix.self::number($low).' à '.self::number($high).' '.$symbol,
                    'amount' => min($low, $high),
                    'currency' => $code,
                ];
            }
        }

        $amount = count($numbers) === 1 ? self::toFloat($numbers[0]) : null;
        if ($amount !== null && mb_strlen($rest) <= 24) {
            $suffix = $rest === '' ? '' : (str_starts_with($middle, '/') ? '/'.$rest : ' '.$rest);

            return ['display' => $prefix.self::format($amount, $code).$suffix, 'amount' => $amount, 'currency' => $code];
        }

        // « Sur devis », « Gratuit », « Nous consulter » : le texte est gardé tel quel.
        return ['display' => Text::limit($text, 80), 'amount' => null, 'currency' => null];
    }

    public static function format(float $amount, string $code): string
    {
        return self::number($amount).' '.self::symbol($code);
    }

    /** « Disponible », « 0 », « rupture », « sur commande »... ramenés à quelques mentions claires. */
    public static function availability(string $raw): ?string
    {
        $folded = trim(preg_replace('/[^a-z0-9]+/', ' ', Text::fold($raw)));
        if ($folded === '') {
            return null;
        }

        // « Disponible sous 48h », « Sur commande 3 jours » : le détail compte, on garde les mots du commerçant.
        if (! is_numeric($folded) && (str_word_count($folded) > 3 || preg_match('/\d/', $folded))) {
            return Text::limit(trim($raw), 60);
        }

        if (preg_match('/\b(pas|plus|non|sans)\b.*\b(stock|disponible|dispo)\b|\brupture\b|\bepuise\b|\bindisponible\b|\bout of stock\b|\bsold out\b|\bunavailable\b/', $folded)
            || in_array($folded, ['non', 'no', 'false', 'faux', '0'], true)) {
            return 'Rupture de stock';
        }
        if (preg_match('/\b(sur commande|a la commande|available for order|pre ?order|precommande|bientot)\b/', $folded)) {
            return 'Sur commande';
        }
        if (preg_match('/\b(discontinued|arrete|plus commercialise)\b/', $folded)) {
            return 'Plus commercialisé';
        }
        if (preg_match('/\b(en stock|in stock|disponible|available|dispo)\b/', $folded)
            || in_array($folded, ['oui', 'yes', 'true', 'vrai', 'ok'], true)) {
            return 'En stock';
        }
        if (is_numeric($folded)) {
            return (float) $folded > 0 ? 'En stock' : 'Rupture de stock';
        }

        return Text::limit(trim($raw), 60);
    }

    public static function symbol(string $code): string
    {
        return Currency::ALL[$code]['symbol'] ?? $code;
    }

    private static function number(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return number_format($amount, $decimals, ',', "\u{202F}");
    }

    /** « 18.000 », « 1 500,50 », « 12,5 », « 1,234,567 » : le dernier séparateur suivi de 1 ou 2 chiffres est la virgule décimale. */
    private static function toFloat(string $group): ?float
    {
        $s = preg_replace('/[\s\x{202F}]/u', '', $group);
        $dots = substr_count($s, '.');
        $commas = substr_count($s, ',');

        if ($dots && $commas) {
            $decimal = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $s = str_replace($decimal, '.', str_replace($thousands, '', $s));
        } elseif ($dots + $commas > 1) {
            $s = str_replace([',', '.'], '', $s);
        } elseif ($dots + $commas === 1) {
            $separator = $dots ? '.' : ',';
            [$before, $after] = explode($separator, $s);
            $s = (strlen($after) === 3 && strlen($before) >= 1 && strlen($before) <= 3 && $before !== '0') ? $before.$after : $before.'.'.$after;
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
