<?php

namespace App\Social;

use App\Services\PlatformSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Connexion officielle d'une page Facebook par l'API Graph, avec l'autorisation de son administrateur.
 * Fonctionne pour les comptes ayant un role sur notre application Meta en mode developpement ; pour tous les
 * clients, l'application doit obtenir l'acces avance (revue d'application) aux permissions pages_show_list
 * et pages_read_engagement.
 */
class FacebookGraph
{
    private const DAYS = [
        'mon' => 'Lundi', 'tue' => 'Mardi', 'wed' => 'Mercredi', 'thu' => 'Jeudi', 'fri' => 'Vendredi', 'sat' => 'Samedi', 'sun' => 'Dimanche',
    ];

    public function __construct(private readonly PlatformSettings $settings) {}

    public function appId(): ?string
    {
        return $this->settings->get('facebook.app_id') ?: config('platform.facebook.app_id');
    }

    private function appSecret(): ?string
    {
        return $this->settings->get('facebook.app_secret') ?: config('platform.facebook.app_secret');
    }

    public function isConfigured(): bool
    {
        return filled($this->appId()) && filled($this->appSecret());
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', config('platform.facebook.scopes')),
            'response_type' => 'code',
        ]);
    }

    /** Echange le code d'autorisation contre un jeton d'utilisateur. */
    public function exchangeCode(string $code, string $redirectUri): string
    {
        $response = Http::timeout(20)->get($this->graph('oauth/access_token'), [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        return (string) $this->json($response)['access_token'];
    }

    /**
     * Pages administrees par l'utilisateur (avec le jeton propre a chaque page).
     *
     * @return list<array{id:string,name:string,access_token:string,link:?string,category:?string}>
     */
    public function pages(string $userToken): array
    {
        $response = Http::timeout(20)->get($this->graph('me/accounts'), [
            'fields' => 'id,name,access_token,link,category',
            'limit' => 50,
            'access_token' => $userToken,
        ]);

        return array_values(array_map(fn ($p) => [
            'id' => (string) $p['id'],
            'name' => (string) ($p['name'] ?? ''),
            'access_token' => (string) ($p['access_token'] ?? ''),
            'link' => $p['link'] ?? null,
            'category' => $p['category'] ?? null,
        ], $this->json($response)['data'] ?? []));
    }

    /**
     * Contenu de la page mis en forme pour la base de connaissances.
     *
     * @return array{title:string, text:string, url:?string}
     */
    public function pageContent(string $pageId, string $pageToken): array
    {
        $page = $this->json(Http::timeout(20)->get($this->graph($pageId), [
            'fields' => 'name,about,description,category,phone,emails,website,single_line_address,hours,link,fan_count',
            'access_token' => $pageToken,
        ]));

        $posts = [];
        try {
            $posts = $this->json(Http::timeout(20)->get($this->graph($pageId.'/posts'), [
                'fields' => 'message,created_time,permalink_url',
                'limit' => 25,
                'access_token' => $pageToken,
            ]))['data'] ?? [];
        } catch (RuntimeException) {
            // Publications inaccessibles (permission manquante) : les informations de la page suffisent.
        }

        $lines = ['# '.($page['name'] ?? 'Page Facebook')];
        foreach (['about' => null, 'description' => null] as $field => $_) {
            if (! empty($page[$field])) {
                $lines[] = "\n".trim($page[$field]);
            }
        }

        $info = array_filter([
            'Catégorie' => $page['category'] ?? null,
            'Adresse' => $page['single_line_address'] ?? null,
            'Téléphone' => $page['phone'] ?? null,
            'E-mail' => isset($page['emails']) ? implode(', ', (array) $page['emails']) : null,
            'Site web' => $page['website'] ?? null,
        ]);
        if ($info) {
            $lines[] = "\n## Informations";
            foreach ($info as $label => $value) {
                $lines[] = "- {$label} : {$value}";
            }
        }

        if ($hours = $this->formatHours($page['hours'] ?? [])) {
            $lines[] = "\n## Horaires";
            array_push($lines, ...$hours);
        }

        $posts = array_values(array_filter($posts, fn ($p) => ! empty(trim((string) ($p['message'] ?? '')))));
        if ($posts) {
            $lines[] = "\n## Publications récentes";
            foreach (array_slice($posts, 0, 25) as $post) {
                $date = substr((string) ($post['created_time'] ?? ''), 0, 10);
                $lines[] = "\n### Publication du {$date}\n".mb_substr(trim($post['message']), 0, 700);
            }
        }

        return ['title' => (string) ($page['name'] ?? 'Page Facebook'), 'text' => implode("\n", $lines), 'url' => $page['link'] ?? null];
    }

    /**
     * L'API renvoie les horaires sous la forme mon_1_open, mon_1_close... (jusqu'a deux plages par jour).
     *
     * @param  array<string,string>  $hours
     * @return list<string>
     */
    private function formatHours(array $hours): array
    {
        $lines = [];
        foreach (self::DAYS as $key => $label) {
            $slots = [];
            foreach ([1, 2] as $n) {
                if (isset($hours["{$key}_{$n}_open"], $hours["{$key}_{$n}_close"])) {
                    $slots[] = 'de '.$hours["{$key}_{$n}_open"].' à '.$hours["{$key}_{$n}_close"];
                }
            }
            if ($slots) {
                $lines[] = "- {$label} : ".implode(' et ', $slots);
            }
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            $message = $response->json('error.message', 'erreur inconnue');
            $expired = in_array((int) $response->json('error.code'), [190, 102], true);

            throw new RuntimeException($expired ? 'Autorisation Facebook expirée : reconnectez la page.' : 'Facebook : '.$message);
        }

        return $response->json() ?? [];
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    private function version(): string
    {
        return config('platform.facebook.graph_version', 'v23.0');
    }
}
