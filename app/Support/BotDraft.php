<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Brouillon de la création d'un assistant : ce que la personne a saisi est enregistré au fil de l'eau (côté serveur,
 * pour la retrouver sur un autre appareil) et rendu à la prochaine visite de la page. Il vit dans le cache de
 * l'application (table `cache` en production), 90 jours, par personne et par espace ; il disparaît quand l'assistant
 * est créé. Rien n'est créé tant que la personne n'a pas cliqué sur « Créer mon assistant ».
 */
final class BotDraft
{
    /** Les seuls champs gardés : le formulaire de création. */
    public const FIELDS = ['name', 'sector', 'language', 'languages', 'description', 'city', 'country', 'hours', 'phone', 'email', 'website', 'offers', 'extra_rules', 'tone', 'formality', 'emojis', 'length'];

    private const TTL_DAYS = 90;

    private static function key(User $user, Workspace $workspace): string
    {
        return "bot-draft:{$workspace->id}:{$user->id}";
    }

    /** @return array{fields:array<string,mixed>, saved_at:string}|null */
    public static function get(User $user, Workspace $workspace): ?array
    {
        $draft = Cache::get(self::key($user, $workspace));

        return is_array($draft) && isset($draft['fields']) ? $draft : null;
    }

    /**
     * Enregistre le brouillon (remplace le précédent). Un formulaire vide supprime le brouillon.
     *
     * @param  array<string,mixed>  $input
     * @return ?array{fields:array<string,mixed>, saved_at:string}
     */
    public static function put(User $user, Workspace $workspace, array $input): ?array
    {
        $fields = self::clean($input);

        if (! self::meaningful($fields)) {
            self::forget($user, $workspace);

            return null;
        }

        $draft = ['fields' => $fields, 'saved_at' => now()->toIso8601String()];
        Cache::put(self::key($user, $workspace), $draft, now()->addDays(self::TTL_DAYS));

        return $draft;
    }

    public static function forget(User $user, Workspace $workspace): void
    {
        Cache::forget(self::key($user, $workspace));
    }

    /** Un nom, ou un peu de texte : sinon ce n'est pas un brouillon, juste un formulaire resté vide. */
    private static function meaningful(array $fields): bool
    {
        foreach (['name', 'description', 'city', 'hours', 'phone', 'email', 'website', 'offers', 'extra_rules'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function clean(array $input): array
    {
        $limits = ['name' => 80, 'description' => 600, 'extra_rules' => 1500, 'offers' => 300, 'hours' => 300, 'website' => 200, 'email' => 190, 'city' => 100, 'country' => 100, 'phone' => 60];
        $fields = [];

        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if ($key === 'languages') {
                $fields[$key] = Languages::normalize(is_array($input[$key]) ? $input[$key] : [], is_string($input['language'] ?? null) ? $input['language'] : null);

                continue;
            }

            if (! is_scalar($input[$key])) {
                continue;
            }

            $value = trim((string) $input[$key]);

            if ($key === 'sector' && ! array_key_exists($value, config('sectors'))) {
                continue;
            }
            if ($key === 'language' && ! Languages::has($value)) {
                continue;
            }

            $fields[$key] = Str::limit($value, $limits[$key] ?? 40, '');
        }

        return $fields;
    }
}
