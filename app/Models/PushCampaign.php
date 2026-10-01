<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un envoi de l'équipe aux clients : promotion, nouveauté ou message important. Il se prépare (brouillon), se programme
 * ou part tout de suite, et s'envoie par morceaux (voir App\Notify\CampaignSender) : les résultats se lisent ici.
 */
class PushCampaign extends Model
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const CANCELED = 'canceled';

    public const PROMO = 'promo';

    public const IMPORTANT = 'important';

    public const STATUSES = [
        self::DRAFT => 'Brouillon',
        self::SCHEDULED => 'Programmée',
        self::SENDING => 'En cours d\'envoi',
        self::SENT => 'Envoyée',
        self::CANCELED => 'Annulée',
    ];

    protected $guarded = [];

    protected $attributes = [
        'kind' => self::PROMO, 'status' => self::DRAFT, 'also_email' => false, 'cursor_user_id' => 0,
        'targeted' => 0, 'notified' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'emailed' => 0,
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array', 'also_email' => 'boolean', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'campaign_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('scheduled_at', '<=', now());
    }

    /** Combien de personnes ont ouvert le message (lu dans l'application ou touché sur le téléphone). */
    public function readCount(): int
    {
        return $this->notifications()->whereNotNull('read_at')->count();
    }

    public function readRate(): float
    {
        return $this->notified > 0 ? round(100 * $this->readCount() / $this->notified, 1) : 0.0;
    }
}
