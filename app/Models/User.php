<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const CLIENT = 'client';

    /** Clef de session : espace client dans lequel le personnel est « entré » pour le gérer. */
    public const ACTING_SESSION_KEY = 'acting_workspace_id';

    /**
     * Mass assignable : role et is_active ne le sont volontairement pas
     * (un visiteur ne doit jamais pouvoir se donner un role a l'inscription).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'workspace_id',
    ];

    protected $attributes = [
        'role' => self::CLIENT,
        'is_active' => true,
        'has_password' => true,
        'needs_profile' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'has_password' => 'boolean',
            'needs_profile' => 'boolean',
            'last_login_at' => 'datetime',
            'pwa_installed_at' => 'datetime',
            'crm_next_follow_up_at' => 'datetime',
            'crm_last_contacted_at' => 'datetime',
            'notification_prefs' => 'array',
        ];
    }

    private ?Workspace $actingWorkspaceCache = null;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Les comptes Google, Apple, Microsoft ou Facebook reliés à celui-ci. */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Comment la personne s'est inscrite (les comptes d'avant la connexion externe n'ont pas de valeur : e-mail). */
    public function signupSource(): string
    {
        return $this->signup_source ?: ($this->isStaff() ? 'team' : 'email');
    }

    /** Les appareils de cette personne qui acceptent les notifications. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Le centre de notifications de cette personne (la table Laravel « notifications » n'est pas utilisée). */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function unreadNotificationCount(): int
    {
        return $this->appNotifications()->whereNull('read_at')->count();
    }

    /**
     * Ce que la personne accepte de recevoir : une case par catégorie, le push (téléphone) en bloc, et ses heures calmes.
     * Les catégories verrouillées (messages importants) sont toujours reçues.
     *
     * @return array{push:bool, cats:array<string,bool>, quiet:array{on:bool,from:int,to:int}}
     */
    public function notificationPrefs(): array
    {
        $saved = (array) ($this->notification_prefs ?? []);
        $cats = [];

        foreach (config('notifications.categories') as $key => $def) {
            $cats[$key] = ($def['locked'] ?? false) ? true : (bool) (($saved['cats'] ?? [])[$key] ?? true);
        }

        return [
            'push' => (bool) ($saved['push'] ?? true),
            'cats' => $cats,
            'quiet' => [
                'on' => (bool) (($saved['quiet'] ?? [])['on'] ?? true),
                'from' => (int) (($saved['quiet'] ?? [])['from'] ?? config('notifications.quiet_hours.from')),
                'to' => (int) (($saved['quiet'] ?? [])['to'] ?? config('notifications.quiet_hours.to')),
            ],
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::SUPER_ADMIN;
    }

    /** Personnel de la plateforme : super admin ou admin. */
    public function isStaff(): bool
    {
        return in_array($this->role, [self::SUPER_ADMIN, self::ADMIN], true);
    }

    public function isClient(): bool
    {
        return $this->role === self::CLIENT;
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::SUPER_ADMIN => 'Super admin',
            self::ADMIN => 'Admin',
            default => 'Client',
        };
    }

    /**
     * Espace client sur lequel portent les requetes de cet utilisateur : le sien pour un client,
     * celui dans lequel il est « entre » pour le personnel (aucun tant qu'il n'est entre nulle part).
     */
    public function currentWorkspaceId(): ?int
    {
        if ($this->isStaff()) {
            return session(self::ACTING_SESSION_KEY) ? (int) session(self::ACTING_SESSION_KEY) : null;
        }

        return $this->workspace_id;
    }

    public function currentWorkspace(): ?Workspace
    {
        $id = $this->currentWorkspaceId();

        if (! $id) {
            return null;
        }

        if ($this->actingWorkspaceCache?->id !== $id) {
            $this->actingWorkspaceCache = Workspace::find($id);
        }

        return $this->actingWorkspaceCache;
    }

    public function isActingAsClient(): bool
    {
        return $this->isStaff() && $this->currentWorkspaceId() !== null;
    }
}
