<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une visite (ou une session de travail d'un client) : de la première page à la dernière, avec sa provenance et son
 * appareil. Volontairement SANS le trait BelongsToWorkspace : ces données servent à la plateforme, jamais aux clients.
 */
class AnalyticsSession extends Model
{
    public const VISITOR = 'visitor';

    public const CLIENT = 'client';

    public const STAFF = 'staff';

    protected $guarded = [];

    /** Valeurs par défaut présentes aussi en mémoire (celles de la base ne s'appliquent qu'à l'insertion). */
    protected $attributes = [
        'audience' => self::VISITOR,
        'pageviews' => 0,
        'events' => 0,
        'duration_seconds' => 0,
        'is_bounce' => true,
        'is_new' => true,
        'is_pwa' => false,
        'source' => 'direct',
        'device' => 'desktop',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_bounce' => 'boolean',
            'is_new' => 'boolean',
            'is_pwa' => 'boolean',
        ];
    }

    /** Les gestes de la visite (le nom « events » est pris par la colonne qui les compte). */
    public function trail(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'session_id');
    }
}
