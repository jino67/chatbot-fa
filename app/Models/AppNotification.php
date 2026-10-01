<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une notification du centre de notifications d'une personne (la cloche, et le compteur sur l'icône de l'application).
 * Lue uniquement à partir de l'utilisateur connecté (`$user->appNotifications()`).
 */
class AppNotification extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'push_after' => 'datetime', 'pushed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PushCampaign::class, 'campaign_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** Le libellé de la catégorie, pour l'étiquette dans la liste. */
    public function categoryLabel(): string
    {
        return (string) (config("notifications.categories.{$this->category}.label") ?? $this->category);
    }
}
