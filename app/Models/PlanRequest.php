<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de changement d'offre faite par un client, traitee par l'equipe. */
class PlanRequest extends Model
{
    use BelongsToWorkspace;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['workspace_id', 'requester_id', 'plan', 'message', 'status', 'admin_notes', 'handled_by'];

    protected $attributes = ['status' => self::REQUESTED];

    public const ADDON_PREFIX = 'addon:';

    public function isAddon(): bool
    {
        return str_starts_with($this->plan, self::ADDON_PREFIX);
    }

    /** Clé de l'option demandée (« chat_import »), ou null pour une offre. */
    public function addonKey(): ?string
    {
        return $this->isAddon() ? substr($this->plan, strlen(self::ADDON_PREFIX)) : null;
    }

    /** Nom lisible de ce qui est demandé : l'offre ou l'option. */
    public function label(): string
    {
        if ($key = $this->addonKey()) {
            return 'Option : '.(config("platform.billing.addons.{$key}.name") ?? $key);
        }

        return Plan::bySlug($this->plan)?->name ?? $this->plan;
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
