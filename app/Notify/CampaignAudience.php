<?php

namespace App\Notify;

use App\Models\User;
use App\Services\Analytics\Tracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * À qui s'adresse une campagne : tous les clients, ceux d'une ou plusieurs offres, des entreprises choisies, ou ceux qui ne
 * sont pas revenus depuis un moment. Seuls les clients actifs d'espaces non suspendus sont visés ; l'équipe ne l'est jamais
 * (elle fait ses essais avec le bouton « M'envoyer un essai »).
 *
 * Forme enregistrée : ['type' => all|plans|workspaces|inactive, 'plans' => [slug], 'workspaces' => [id], 'days' => 14, 'only_push' => bool]
 */
final class CampaignAudience
{
    public const TYPES = [
        'all' => 'Tous les clients',
        'plans' => 'Les clients d\'une ou plusieurs offres',
        'workspaces' => 'Des entreprises choisies',
        'inactive' => 'Les clients qui ne sont pas revenus depuis un moment',
    ];

    /** @param array<string,mixed> $audience @return Builder<User> */
    public static function query(array $audience): Builder
    {
        $query = User::query()
            ->where('users.role', User::CLIENT)->where('users.is_active', true)
            ->whereNotNull('users.workspace_id')
            ->whereIn('users.workspace_id', DB::table('workspaces')->where('is_suspended', false)->select('id'));

        switch ($audience['type'] ?? 'all') {
            case 'plans':
                $query->whereIn('users.workspace_id', DB::table('workspaces')->whereIn('plan', (array) ($audience['plans'] ?? []))->select('id'));
                break;

            case 'workspaces':
                $query->whereIn('users.workspace_id', array_map('intval', (array) ($audience['workspaces'] ?? [])));
                break;

            case 'inactive':
                // Les espaces sans visite d'un client depuis N jours (la mesure d'audience fait foi ; un espace jamais visité compte aussi).
                $since = now(Tracker::timezone())->subDays(max(1, (int) ($audience['days'] ?? 14)))->toDateString();
                $query->whereNotIn('users.workspace_id', DB::table('analytics_sessions')->where('audience', 'client')->where('date', '>=', $since)->whereNotNull('workspace_id')->select('workspace_id'));
                break;
        }

        if (! empty($audience['only_push'])) {
            $query->whereIn('users.id', DB::table('push_subscriptions')->select('user_id'));
        }

        return $query;
    }

    /**
     * Combien de personnes et d'appareils seront touchés : de quoi décider avant d'envoyer.
     *
     * @param  array<string,mixed>  $audience
     * @return array{users:int, workspaces:int, with_push:int, devices:int}
     */
    public static function estimate(array $audience): array
    {
        $ids = self::query($audience)->pluck('users.id');

        return [
            'users' => $ids->count(),
            'workspaces' => self::query($audience)->distinct()->count('users.workspace_id'),
            'with_push' => $ids->isEmpty() ? 0 : (int) DB::table('push_subscriptions')->whereIn('user_id', $ids)->distinct()->count('user_id'),
            'devices' => $ids->isEmpty() ? 0 : (int) DB::table('push_subscriptions')->whereIn('user_id', $ids)->count(),
        ];
    }

    /** Une phrase qui décrit l'audience, pour l'historique. @param array<string,mixed> $audience */
    public static function describe(array $audience): string
    {
        $text = match ($audience['type'] ?? 'all') {
            'plans' => 'Offres : '.(implode(', ', (array) ($audience['plans'] ?? [])) ?: 'aucune'),
            'workspaces' => count((array) ($audience['workspaces'] ?? [])).' entreprise(s) choisie(s)',
            'inactive' => 'Clients absents depuis '.(int) ($audience['days'] ?? 14).' jours',
            default => 'Tous les clients',
        };

        return $text.(! empty($audience['only_push']) ? ', seulement ceux qui ont activé les notifications' : '');
    }
}
