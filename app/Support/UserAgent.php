<?php

namespace App\Support;

/** Décrit un navigateur en quelques mots (« Chrome sur Windows ») pour la liste des appareils connectés. */
final class UserAgent
{
    /** @return array{browser:string,os:string,mobile:bool,label:string} */
    public static function describe(?string $agent): array
    {
        $ua = (string) $agent;

        // L'ordre compte : Edge et Chrome se disent aussi « Safari », Android se dit aussi « Linux », l'iPhone « Mac OS X ».
        $browser = match (true) {
            str_contains($ua, 'Edg/'), str_contains($ua, 'EdgA/'), str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh'), str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'appareil inconnu',
        };

        return [
            'browser' => $browser,
            'os' => $os,
            'mobile' => in_array($os, ['iPhone', 'iPad', 'Android'], true),
            'label' => $browser.' sur '.$os,
        ];
    }
}
