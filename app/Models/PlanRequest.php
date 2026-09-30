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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
