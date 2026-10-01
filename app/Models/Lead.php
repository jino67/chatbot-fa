<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une demande à traiter par le propriétaire : voir la migration create_leads. */
class Lead extends Model
{
    use BelongsToWorkspace;

    public const ORDER = 'order';

    public const APPOINTMENT = 'appointment';

    public const QUOTE = 'quote';

    public const HUMAN = 'human';

    public const NEW = 'new';

    public const TAKEN = 'taken';

    public const DONE = 'done';

    public const DISMISSED = 'dismissed';

    /** Nom de chaque type, tel que le propriétaire le lit (étiquette et alertes). */
    public const KINDS = [
        self::ORDER => 'Commande à confirmer',
        self::APPOINTMENT => 'Rendez-vous à confirmer',
        self::QUOTE => 'Devis demandé',
        self::HUMAN => 'Demande une personne',
    ];

    /** Étiquette conseillée dans l'application WhatsApp Business, à poser à la main (l'API ne sait pas le faire). */
    public const WHATSAPP_LABELS = [
        self::ORDER => 'Commande à confirmer',
        self::APPOINTMENT => 'Rendez-vous',
        self::QUOTE => 'Devis',
        self::HUMAN => 'À rappeler',
    ];

    protected $fillable = [
        'workspace_id', 'bot_id', 'conversation_id', 'kind', 'status', 'title', 'summary', 'contact_name', 'contact_phone',
        'assigned_to', 'alerted_at', 'alert_log', 'reminders', 'last_reminder_at', 'taken_at', 'closed_at',
    ];

    protected $attributes = ['status' => self::NEW, 'reminders' => 0];

    protected function casts(): array
    {
        return [
            'alert_log' => 'array',
            'alerted_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'taken_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Demandes encore à traiter : nouvelles ou prises en charge. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::NEW, self::TAKEN]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::NEW, self::TAKEN], true);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** Traduit le mot écrit par l'assistant dans le marqueur [[LEAD: ...]] en type de demande. */
    public static function kindFromWord(string $word): ?string
    {
        $word = mb_strtolower(trim(\App\Support\Text::fold($word)));

        return match (true) {
            in_array($word, ['commande', 'order', 'achat', 'commander'], true) => self::ORDER,
            in_array($word, ['rendez-vous', 'rendez vous', 'rdv', 'reservation', 'reserver', 'booking', 'appointment'], true) => self::APPOINTMENT,
            in_array($word, ['devis', 'quote', 'estimation'], true) => self::QUOTE,
            default => null,
        };
    }
}
