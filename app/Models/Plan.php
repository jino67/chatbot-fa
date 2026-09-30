<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'slug', 'name', 'tagline', 'price', 'currency', 'period_months', 'limits', 'features',
        'is_public', 'is_default', 'is_highlighted', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'is_highlighted' => 'boolean',
        ];
    }

    /** Limites minimales appliquees si une offre a ete supprimee alors qu'un espace la reference encore. */
    public const FALLBACK_LIMITS = ['bots' => 1, 'sources' => 4, 'pages_per_crawl' => 10, 'messages_per_month' => 100, 'members' => 1];

    public static function bySlug(?string $slug): ?self
    {
        return $slug ? static::where('slug', $slug)->first() : null;
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->orderBy('sort')->first() ?? static::orderBy('sort')->first();
    }

    /** @return list<array{key:string,label:string,type:string}> champs de quotas affiches et editables */
    public static function limitFields(): array
    {
        return [
            ['key' => 'bots', 'label' => 'Assistants', 'type' => 'int'],
            ['key' => 'sources', 'label' => 'Sources de connaissances', 'type' => 'int'],
            ['key' => 'pages_per_crawl', 'label' => 'Pages lues par site', 'type' => 'int'],
            ['key' => 'messages_per_month', 'label' => 'Réponses de l\'assistant par mois', 'type' => 'int'],
            ['key' => 'members', 'label' => 'Utilisateurs', 'type' => 'int'],
        ];
    }

    /** @return list<array{key:string,label:string}> options incluses ou non */
    public static function featureFields(): array
    {
        return [
            ['key' => 'whatsapp', 'label' => 'WhatsApp'],
            ['key' => 'templates', 'label' => 'Modèles de messages WhatsApp'],
            ['key' => 'remove_branding', 'label' => 'Sans publicité « Propulsé par »'],
            ['key' => 'priority_support', 'label' => 'Assistance prioritaire'],
        ];
    }

    public function limit(string $key): int
    {
        return (int) ($this->limits[$key] ?? self::FALLBACK_LIMITS[$key] ?? 0);
    }

    public function feature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function formattedPrice(): string
    {
        if ($this->price === 0) {
            return 'Gratuit';
        }

        $symbol = match ($this->currency) {
            'XOF' => 'FCFA',
            'EUR' => '€',
            'USD' => '$',
            default => $this->currency,
        };

        return number_format($this->price, 0, ',', "\u{202F}").' '.$symbol;
    }
}
