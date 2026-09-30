<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'activation WhatsApp faite par un client, traitee par l'equipe technique. */
class ChannelRequest extends Model
{
    use BelongsToWorkspace;

    public const REQUESTED = 'requested';

    public const IN_PROGRESS = 'in_progress';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id', 'bot_id', 'requester_id', 'business_name', 'phone_number', 'country',
        'notes', 'status', 'admin_notes', 'handled_by',
    ];

    protected $attributes = ['status' => self::REQUESTED];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::REQUESTED => 'Demande reçue',
            self::IN_PROGRESS => 'En cours de configuration',
            self::ACTIVE => 'Activé',
            self::REJECTED => 'Refusé',
            default => $this->status,
        };
    }
}
