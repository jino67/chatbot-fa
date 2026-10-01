<?php

namespace App\Support;

use App\Models\User;
use App\Services\Analytics\Tracker;

/**
 * Point d'entrée court pour enregistrer une action de l'application : Analytics::action('bot.created', ['sector' => 'commerce']).
 * L'action est rattachée à la visite en cours, au compte et à l'espace ; ne lève jamais d'exception.
 */
final class Analytics
{
    /** @param array<string,scalar|null> $props */
    public static function action(string $name, array $props = [], ?User $user = null, ?int $workspaceId = null): void
    {
        app(Tracker::class)->action($name, $props, $user, $workspaceId);
    }
}
