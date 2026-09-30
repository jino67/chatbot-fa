<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    public const ACTIVE = 'active';

    public const TRIALING = 'trialing';

    public const PAST_DUE = 'past_due';

    public const CANCELED = 'canceled';

    protected $fillable = [
        'name', 'slug', 'plan', 'subscription_status', 'plan_started_at', 'plan_ends_at',
        'is_suspended', 'suspended_reason', 'country', 'phone', 'notes', 'settings',
    ];

    protected $attributes = [
        'plan' => 'free',
        'subscription_status' => self::ACTIVE,
        'is_suspended' => false,
    ];

    private ?Plan $planCache = null;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_suspended' => 'boolean',
            'plan_started_at' => 'datetime',
            'plan_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });

        static::saved(fn (Workspace $workspace) => $workspace->planCache = null);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entreprise';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Offre courante. Si elle a ete supprimee, l'offre par defaut prend le relais (jamais d'erreur). */
    public function planModel(): ?Plan
    {
        return $this->planCache ??= Plan::bySlug($this->plan) ?? Plan::default();
    }

    /** Limites de l'offre courante. */
    public function limits(): array
    {
        $plan = $this->planModel();

        return ($plan?->limits ?? []) + Plan::FALLBACK_LIMITS + ['label' => $plan?->name ?? 'Découverte'];
    }

    public function limit(string $key): int
    {
        return $this->planModel()?->limit($key) ?? (Plan::FALLBACK_LIMITS[$key] ?? 0);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) $this->planModel()?->feature($feature);
    }

    public function isPaid(): bool
    {
        return ! ($this->planModel()?->isFree() ?? true);
    }

    /** Jours restants avant la fin de la periode payee (negatif si depassee, null si sans echeance). */
    public function daysLeft(): ?int
    {
        return $this->plan_ends_at ? (int) floor(now()->diffInDays($this->plan_ends_at, false)) : null;
    }

    public function statusLabel(): string
    {
        if ($this->is_suspended) {
            return 'Suspendu';
        }

        return match ($this->subscription_status) {
            self::TRIALING => 'Essai',
            self::PAST_DUE => 'Paiement en retard',
            self::CANCELED => 'Résilié',
            default => 'Actif',
        };
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_at');
    }

    public function owner(): ?User
    {
        return $this->users()->where('role', User::CLIENT)->orderBy('id')->first();
    }
}
