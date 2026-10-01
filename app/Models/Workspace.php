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

    /** Essai gratuit terminé : l'assistant ne répond plus tant que le client n'a pas choisi une offre. */
    public const EXPIRED = 'expired';

    protected $fillable = [
        'name', 'slug', 'plan', 'currency', 'wa_credit', 'subscription_status', 'plan_started_at', 'plan_ends_at',
        'is_suspended', 'suspended_reason', 'country', 'phone', 'notes', 'settings',
    ];

    protected $attributes = [
        'plan' => 'free',
        'currency' => 'XOF',
        'wa_credit' => 0,
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

    /** Une fonction vient de l'offre, ou d'une option achetée à la carte (activée par l'équipe après paiement). */
    public function hasFeature(string $feature): bool
    {
        return (bool) $this->planModel()?->feature($feature) || (bool) (($this->settings['addons'] ?? [])[$feature] ?? false);
    }

    public function grantAddon(string $key, bool $enabled = true): void
    {
        $settings = $this->settings ?? [];
        $settings['addons'][$key] = $enabled;
        $this->settings = $settings;
        $this->save();
    }

    /** L'option est-elle achetée à la carte (et non incluse dans l'offre) ? */
    public function hasAddon(string $key): bool
    {
        return (bool) (($this->settings['addons'] ?? [])[$key] ?? false);
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

    /** Sur une offre gratuite dont la date de fin n'est pas encore passee. */
    public function onTrial(): bool
    {
        return ! $this->isPaid() && $this->plan_ends_at?->isFuture() === true;
    }

    /** Offre gratuite dont la date de fin est passee : l'assistant est en pause, les donnees sont conservees. */
    public function trialExpired(): bool
    {
        return ! $this->isPaid() && $this->plan_ends_at?->isPast() === true;
    }

    public function statusLabel(): string
    {
        if ($this->is_suspended) {
            return 'Suspendu';
        }

        if ($this->trialExpired()) {
            return 'Essai terminé';
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

    /**
     * Comment le propriétaire veut être prévenu d'une demande (page Alertes). Sans choix, l'e-mail est actif : le
     * tableau de bord, lui, est toujours alimenté.
     *
     * Destinataires : une adresse principale (au choix, sinon celle de l'assistant, sinon celle du propriétaire), des
     * membres de l'équipe (leur adresse e-mail ou leur numéro de profil) et d'autres adresses ou numéros.
     *
     * @return array{email:bool,email_to:?string,email_members:list<int>,email_extra:list<string>,whatsapp:bool,whatsapp_number:?string,whatsapp_members:list<int>,whatsapp_extra:list<string>,template:string,reminder_minutes:int,kinds:list<string>,chosen:bool}
     */
    public function alertSettings(): array
    {
        $saved = (array) (($this->settings ?? [])['alerts'] ?? []);

        return [
            'email' => (bool) ($saved['email'] ?? true),
            'push' => (bool) ($saved['push'] ?? true),
            'email_to' => $saved['email_to'] ?? null,
            'email_members' => array_map('intval', $saved['email_members'] ?? []),
            'email_extra' => array_values($saved['email_extra'] ?? []),
            'whatsapp' => (bool) ($saved['whatsapp'] ?? false),
            'whatsapp_number' => $saved['whatsapp_number'] ?? null,
            'whatsapp_members' => array_map('intval', $saved['whatsapp_members'] ?? []),
            'whatsapp_extra' => array_values($saved['whatsapp_extra'] ?? []),
            'template' => (string) ($saved['template'] ?? 'alerte_demande'),
            'reminder_minutes' => (int) ($saved['reminder_minutes'] ?? 30),
            'kinds' => array_values($saved['kinds'] ?? array_keys(Lead::KINDS)),
            'chosen' => (bool) ($saved['chosen'] ?? false),
        ];
    }
}
