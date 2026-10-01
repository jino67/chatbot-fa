<?php

namespace App\Support;

use App\Services\PlatformSettings;

/**
 * Les devises de la plateforme : FCFA (XOF), franc comorien (KMF), euro, dollar et dirham marocain (MAD).
 * Chaque offre a un prix par devise. La devise est propre à chaque compte : un client connecté (ou le personnel entré
 * dans son espace) a celle de son espace, FCFA par défaut, et la change sans rien changer pour les autres. Seul un
 * visiteur non connecté a un choix mémorisé, en session et dans un cookie d'un an (il le retrouve à sa prochaine visite).
 */
final class Currency
{
    /** Cookie du visiteur non connecté : son choix survit à la session. */
    public const COOKIE = 'currency';

    /** @var array<string,array{name:string,symbol:string,short:string}> */
    public const ALL = [
        'XOF' => ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'short' => 'FCFA'],
        'KMF' => ['name' => 'Franc comorien', 'symbol' => 'KMF', 'short' => 'KMF'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'short' => 'EUR'],
        'USD' => ['name' => 'Dollar US', 'symbol' => '$', 'short' => 'USD'],
        'MAD' => ['name' => 'Dirham marocain', 'symbol' => 'DH', 'short' => 'MAD'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::ALL[$code]);
    }

    /** Devise par défaut de la plateforme (réglable dans les paramètres). */
    public static function default(): string
    {
        $code = app(PlatformSettings::class)->get('billing.currency');

        return self::isValid($code) ? $code : 'XOF';
    }

    /**
     * Devise à afficher maintenant : celle de l'espace dans lequel on se trouve (le client, ou l'espace où le personnel
     * est entré), sinon le choix du visiteur, sinon le défaut. Jamais la session d'un autre compte.
     */
    public static function current(): string
    {
        $workspace = auth()->user()?->currentWorkspace();
        $code = $workspace ? $workspace->currency : (session('currency') ?: request()->cookie(self::COOKIE));

        return self::isValid($code) ? $code : self::default();
    }

    /**
     * Memorise la devise choisie : sur l'espace quand on est dans un espace (rien en session, pour qu'elle ne suive pas
     * le navigateur d'un compte à l'autre), en session pour un visiteur.
     */
    public static function remember(\Illuminate\Http\Request $request, string $code): void
    {
        $workspace = $request->user()?->currentWorkspace();

        if ($workspace) {
            if ($workspace->currency !== $code) {
                $workspace->update(['currency' => $code]);
            }

            return;
        }

        $request->session()->put('currency', $code);
        \Illuminate\Support\Facades\Cookie::queue(self::COOKIE, $code, 60 * 24 * 365);
    }

    /** @param  array<string,int|float>  $prices  montant par devise  @return array<string,string> le meme, formate (pour le selecteur de devise) */
    public static function formatPrices(array $prices): array
    {
        $fallback = $prices['XOF'] ?? reset($prices);

        return collect(self::codes())->mapWithKeys(fn ($code) => [$code => self::format($prices[$code] ?? $fallback, isset($prices[$code]) ? $code : 'XOF')])->all();
    }

    public static function symbol(string $code): string
    {
        return self::ALL[$code]['symbol'] ?? $code;
    }

    /** « 10 000 FCFA », « 15 € », « 17 $ » : sans décimales, espace fine insécable entre les milliers. */
    public static function format(int|float $amount, string $code): string
    {
        return number_format($amount, 0, ',', "\u{202F}").' '.self::symbol($code);
    }
}
