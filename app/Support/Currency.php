<?php

namespace App\Support;

use App\Services\PlatformSettings;

/**
 * Les devises de la plateforme : FCFA (XOF), franc comorien (KMF), euro, dollar et dirham marocain (MAD).
 * Chaque offre a un prix par devise ; le visiteur en choisit une (mémorisée en session), le client
 * garde la sienne sur son espace.
 */
final class Currency
{
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

    /** Devise à afficher maintenant : celle de l'espace du client connecté, sinon le choix du visiteur, sinon le défaut. */
    public static function current(): string
    {
        $code = auth()->user()?->workspace?->currency ?: session('currency');

        return self::isValid($code) ? $code : self::default();
    }

    /** Memorise la devise choisie : en session pour un visiteur, et sur l'espace pour un client connecte. */
    public static function remember(\Illuminate\Http\Request $request, string $code): void
    {
        $request->session()->put('currency', $code);

        $workspace = $request->user()?->workspace;
        if ($workspace && $workspace->currency !== $code) {
            $workspace->update(['currency' => $code]);
        }
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
