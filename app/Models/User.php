<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'password',
        'workspace_id',
    ];

    protected $attributes = [
        'role' => self::CLIENT,
        'is_active' => true,
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
            'last_login_at' => 'datetime',
        ];
    }

    private ?Workspace $actingWorkspaceCache = null;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
