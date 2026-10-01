<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une unite de consommation (reponse IA, message WhatsApp, message vocal) avec son cout estime en dollars. */
class UsageEvent extends Model
{
    use BelongsToWorkspace;

    public const AI_ANSWER = 'ai_answer';

    public const WA_IN = 'wa_in';

    public const WA_OUT = 'wa_out';

    public const WA_TEMPLATE = 'wa_template';

    public const VOICE_IN = 'voice_in';

    public const VOICE_OUT = 'voice_out';

    /** Les trois types de message WhatsApp : ils comptent dans le volume inclus dans l'offre. */
    public const WHATSAPP = [self::WA_IN, self::WA_OUT, self::WA_TEMPLATE];

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'bot_id', 'channel_id', 'kind', 'provider', 'detail', 'units', 'tokens_in', 'tokens_out', 'cost_usd', 'created_at',
    ];

    protected $attributes = ['units' => 1, 'cost_usd' => 0];

    protected function casts(): array
    {
        return ['cost_usd' => 'float', 'created_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::AI_ANSWER => 'Réponse IA',
            self::WA_IN => 'WhatsApp reçu',
            self::WA_OUT => 'WhatsApp envoyé',
            self::WA_TEMPLATE => 'WhatsApp modèle',
            self::VOICE_IN => 'Vocal écouté',
            self::VOICE_OUT => 'Vocal envoyé',
            default => $this->kind,
        };
    }
}
