<?php

namespace App\Ingestion\Catalog;

/**
 * Repère les prix écrits dans le texte d'une page web : « 5 000 XOF », « 5 000XOF », « 18.000 F CFA », « 12,50 € »,
 * « XOF 5000 ». Un nombre sans monnaie n'est pas un prix (taille, quantité, note) : il faut une monnaie à côté.
 */
final class PriceText
{
    private const CURRENCY = '(?:f\s?cfa|fcfa|cfa|xof|xaf|frs?|€|eur|\$|usd|dhs?|mad|kmf|gnf|ghs|ngn|₦)';

    private const NUMBER = '\d{1,3}(?:[ \x{202F}\x{00A0}.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?';

    /**
     * Les prix distincts d'un texte, sous leur première écriture.
     *
     * @return list<string>
     */
    public static function all(string $text): array
    {
        $pattern = '/(?<![\p{L}\d])(?:(?<n1>'.self::NUMBER.')\s?(?<c1>'.self::CURRENCY.')(?![\p{L}])|(?<c2>'.self::CURRENCY.')\s?(?<n2>'.self::NUMBER.')(?![\d]))/iu';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $seen = [];
        foreach ($matches as $match) {
            $number = ($match['n1'] ?? '') !== '' ? $match['n1'] : ($match['n2'] ?? '');
            $key = preg_replace('/\D+/', '', $number).'|'.mb_strtolower(trim((string) (($match['c1'] ?? '') !== '' ? $match['c1'] : ($match['c2'] ?? ''))));
            $seen[$key] ??= trim($match[0]);
        }

        return array_values($seen);
    }

    /** Montants distincts (chiffres seuls), pour compter combien de prix différents contient un bloc. */
    public static function amounts(string $text): array
    {
        $amounts = [];
        foreach (self::all($text) as $price) {
            $digits = preg_replace('/\D+/', '', preg_replace('/[.,]\d{1,2}(?!\d)/', '', $price));
            if ($digits !== '') {
                $amounts[$digits] = true;
            }
        }

        return array_keys($amounts);
    }

    public static function first(string $text): ?string
    {
        return self::all($text)[0] ?? null;
    }
}
