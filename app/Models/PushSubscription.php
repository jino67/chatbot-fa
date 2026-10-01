<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un appareil qui a accepté de recevoir des notifications. Appartient à une personne (user_id) : toujours lu à partir de
 * l'utilisateur connecté (`$user->pushSubscriptions()`), jamais par identifiant venu du navigateur.
 */
class PushSubscription extends Model
{
    protected $guarded = [];

    protected $attributes = ['platform' => 'other', 'standalone' => false, 'failures' => 0];

    protected function casts(): array
    {
        return ['standalone' => 'boolean', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** « Téléphone Android », « iPhone », « Ordinateur » : de quoi reconnaître l'appareil dans la liste de ses appareils. */
    public function deviceLabel(): string
    {
        return match ($this->platform) {
            'android' => 'Téléphone Android',
            'ios' => 'iPhone ou iPad',
            'desktop' => 'Ordinateur',
            default => 'Appareil',
        };
    }
}
