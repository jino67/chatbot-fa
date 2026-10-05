<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    use BelongsToWorkspace;

    protected $table = 'whatsapp_templates';

    public const APPROVED = 'APPROVED';

    public const PENDING = 'PENDING';

    public const REJECTED = 'REJECTED';

    public const DRAFT = 'DRAFT';

    public const CATEGORIES = [
        'UTILITY' => 'Utilitaire (suivi de commande, rendez-vous, information)',
        'MARKETING' => 'Marketing (promotion, nouveauté)',
        'AUTHENTICATION' => 'Authentification (code de vérification)',
    ];

    public const LANGUAGES = ['fr' => 'Français', 'en' => 'English', 'ar' => 'العربية', 'en_US' => 'English (US)'];

    protected $fillable = [
        'workspace_id', 'channel_id', 'external_id', 'name', 'language', 'category', 'status',
        'components', 'body', 'variables_count', 'rejected_reason', 'last_synced_at',
    ];

    protected $attributes = ['status' => self::DRAFT, 'language' => 'fr', 'category' => 'UTILITY'];

    protected function casts(): array
    {
        return ['components' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approuvé',
            self::PENDING => 'En attente de Meta',
            self::REJECTED => 'Refusé',
            'PAUSED' => 'En pause',
            'DISABLED' => 'Désactivé',
            default => 'Brouillon',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::APPROVED => 'green',
            self::PENDING => 'amber',
            self::REJECTED, 'DISABLED' => 'red',
            default => 'gray',
        };
    }

    /**
     * Nom de chaque variable ({{1}}, {{2}}...) quand le modèle vient de la bibliothèque : « Prénom du client », « Montant »...
     * Vide pour un modèle écrit à la main, ou dont le nombre de variables a changé.
     *
     * @return list<string>
     */
    public function variableLabels(): array
    {
        $labels = config('whatsapp_templates.templates.'.$this->name.'.'.$this->language.'.vars', []);

        return is_array($labels) && count($labels) === (int) $this->variables_count ? array_values($labels) : [];
    }

    /** Remplace {{1}}, {{2}}... par des valeurs (apercu). */
    public function preview(array $values = []): string
    {
        $text = (string) $this->body;

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $values[(int) $m[1] - 1] ?? $m[0], $text);
    }
}
