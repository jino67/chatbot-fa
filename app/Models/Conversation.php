<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use BelongsToWorkspace;

    public const BOT = 'bot';

    public const NEEDS_HUMAN = 'needs_human';

    public const HUMAN = 'human';

    public const CLOSED = 'closed';

    protected $fillable = [
        'workspace_id', 'bot_id', 'channel', 'external_id', 'contact_name', 'contact_phone', 'contact_email',
        'status', 'meta', 'last_message_at', 'last_inbound_at',
    ];

    /** Valeurs par defaut presentes aussi en memoire (celles de la base ne s'appliquent qu'a l'insertion). */
    protected $attributes = [
        'status' => self::BOT,
        'channel' => 'web',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            $conversation->token ??= (string) Str::uuid();
        });
    }

    /** Conversations de vrais visiteurs : exclut les essais faits dans le playground. */
    public function scopeReal($query)
    {
        return $query->where('channel', '!=', 'playground');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** Demandes encore à traiter liées à cette conversation (les « étiquettes » affichées dans la boîte de réception). */
    public function openLeads(): HasMany
    {
        return $this->leads()->whereIn('status', [Lead::NEW, Lead::TAKEN]);
    }

    public function isHandledByHuman(): bool
    {
        return in_array($this->status, [self::NEEDS_HUMAN, self::HUMAN], true);
    }

    /** WhatsApp : un texte libre n'est autorise que 24 h apres le dernier message du client. */
    public function isWithinServiceWindow(): bool
    {
        if ($this->channel !== 'whatsapp') {
            return true;
        }

        return $this->last_inbound_at !== null
            && $this->last_inbound_at->gt(now()->subHours(config('platform.whatsapp.session_window_hours')));
    }

    /** Prénom du client pour pré-remplir un modèle : seulement si le nom commence par une lettre (jamais un numéro). */
    public function firstName(): ?string
    {
        return preg_match('/^\p{L}[\p{L}\'’-]*/u', trim((string) $this->contact_name), $m) ? $m[0] : null;
    }

    public function displayName(): string
    {
        return $this->contact_name
            ?: ($this->contact_phone ?: ($this->channel === 'whatsapp' ? '+'.$this->external_id : 'Visiteur '.Str::substr($this->token, 0, 4)));
    }
}
