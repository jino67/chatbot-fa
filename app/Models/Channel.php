<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Channel extends Model
{
    use BelongsToWorkspace;

    public const WHATSAPP_META = 'whatsapp_meta';

    public const WHATSAPP_TWILIO = 'whatsapp_twilio';

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $fillable = [
        'workspace_id', 'bot_id', 'type', 'status', 'display_phone', 'external_ref',
        'credentials', 'config', 'activated_by', 'activated_at',
    ];

    protected $hidden = ['credentials'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            // Jetons et secrets chiffres au repos avec APP_KEY.
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function providerLabel(): string
    {
        return match ($this->type) {
            self::WHATSAPP_META => 'WhatsApp Cloud API (Meta)',
            self::WHATSAPP_TWILIO => 'WhatsApp via Twilio',
            default => $this->type,
        };
    }
}
