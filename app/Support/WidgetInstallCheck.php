<?php

namespace App\Support;

use App\Models\Bot;

/**
 * Vérifie, à partir de la page que le site d'un client envoie vraiment aux visiteurs, que le script de l'assistant est bien
 * là : présent, avec la bonne clé, pointant vers la plateforme (et pas vers une adresse de test), et sur un site que
 * l'assistant autorise. Chaque cas a son explication : « je ne vois pas la bulle » a presque toujours une de ces causes.
 */
final class WidgetInstallCheck
{
    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    public const COMMENTED = 'commented';

    public const WRONG_KEY = 'wrong_key';

    public const WRONG_HOST = 'wrong_host';

    public const ORIGIN_BLOCKED = 'origin_blocked';

    public const INACTIVE = 'inactive';

    public const UNREACHABLE = 'unreachable';

    /** @return array{status:string, ok:bool, title:string, help:string} */
    public static function analyze(string $html, string $pageUrl, Bot $bot): array
    {
        $platformHost = Text::fold((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $found = [];

        // Les balises <script> du document, sans les commentaires HTML (un script en commentaire ne s'exécute pas).
        $withoutComments = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        if (preg_match_all('/<script\b[^>]*>/i', $withoutComments, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = self::attribute($tag, 'src');
                $key = self::attribute($tag, 'data-bot');
                if ($key !== null || ($src !== null && str_contains($src, '/widget/widget.js'))) {
                    $found[] = ['src' => $src, 'key' => $key];
                }
            }
        }

        if ($found === []) {
            $commented = str_contains($html, 'widget/widget.js') || str_contains($html, 'data-bot=');

            return $commented
                ? self::result(self::COMMENTED, false, 'Le script est dans la page, mais en commentaire.', "Il est entouré de <!-- ... --> : le navigateur l'ignore. Retirez les marques de commentaire autour de la ligne du script.")
                : self::result(self::NOT_FOUND, false, "Le script n'est pas dans la page que votre site envoie aujourd'hui.", "Causes les plus fréquentes : il est collé dans un fichier que cette page n'utilise pas (un autre modèle de page), il n'a pas été mis en ligne sur le serveur (il n'existe que sur votre ordinateur), ou une version en mémoire de la page est encore servie (videz le cache de votre site et de votre hébergeur). Collez la ligne juste avant </body> du modèle utilisé par toutes les pages, mettez le site en ligne, puis vérifiez de nouveau.");
        }

        $mine = array_values(array_filter($found, fn ($f) => $f['key'] === $bot->public_key));
        if ($mine === []) {
            $other = $found[0]['key'] ?? 'inconnue';

            return self::result(self::WRONG_KEY, false, 'Le script de la page est celui d\'un autre assistant.', "La page contient la clé « {$other} » au lieu de « {$bot->public_key} ». Remplacez la ligne par celle de cette page.");
        }

        $host = Text::fold((string) parse_url((string) ($mine[0]['src'] ?? ''), PHP_URL_HOST));
        $port = parse_url((string) ($mine[0]['src'] ?? ''), PHP_URL_PORT);
        if ($host !== '' && $host !== $platformHost) {
            return self::result(self::WRONG_HOST, false, "Le script pointe vers {$host}".($port ? ":{$port}" : '').' et non vers la plateforme.', "Une adresse de test (localhost, 127.0.0.1) a sans doute été copiée depuis votre ordinateur. Collez à la place la ligne affichée sur cette page, qui commence par https://{$platformHost}/widget/widget.js.");
        }

        if (! $bot->is_active) {
            return self::result(self::INACTIVE, false, 'Le script est bien installé, mais l\'assistant est désactivé.', "Réglages de l'assistant : cochez « Assistant actif ». Tant qu'il est désactivé, la bulle ne s'affiche pas.");
        }

        $origin = self::origin($pageUrl);
        if ($origin !== null && ! $bot->allowsOrigin($origin)) {
            return self::result(self::ORIGIN_BLOCKED, false, "Le script est installé, mais l'adresse {$origin} n'est pas autorisée.", "Dans les Réglages de l'assistant, section « Sites autorisés », ajoutez {$origin} (ou videz la liste pour autoriser tous les sites). Sans cela, la bulle reste invisible.");
        }

        return self::result(self::OK, true, 'Installation correcte : la bulle doit s\'afficher sur cette page.', "Si vous ne la voyez pas, rechargez la page en navigation privée (ou videz le cache de votre navigateur et de votre site).");
    }

    /** @return array{status:string, ok:bool, title:string, help:string} */
    public static function unreachable(string $reason): array
    {
        return self::result(self::UNREACHABLE, false, "Impossible d'ouvrir cette page.", $reason);
    }

    /** « https://exemple.com/page » donne « https://exemple.com ». */
    public static function origin(string $url): ?string
    {
        $p = parse_url($url);

        return isset($p['scheme'], $p['host']) ? strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '')) : null;
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (! preg_match('/\s'.preg_quote($name, '/').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return null;
        }

        // Selon les guillemets utilisés, la valeur est dans le groupe 1, 2 ou 3.
        $value = ($m[3] ?? '') !== '' ? $m[3] : (($m[2] ?? '') !== '' ? $m[2] : $m[1]);

        return html_entity_decode($value);
    }

    /** @return array{status:string, ok:bool, title:string, help:string} */
    private static function result(string $status, bool $ok, string $title, string $help): array
    {
        return ['status' => $status, 'ok' => $ok, 'title' => $title, 'help' => $help];
    }
}
