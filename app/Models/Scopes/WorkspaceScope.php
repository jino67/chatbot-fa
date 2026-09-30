<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isolation multi-tenant : quand un utilisateur est connecte, toute requete sur un modele "tenant"
 * est filtree sur son espace courant (le sien pour un client, celui ou le personnel est « entre »).
 * Sans utilisateur (webhooks, jobs, widget public) le filtrage est explicite dans le code appelant.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = auth()->user()?->currentWorkspaceId();

        if ($workspaceId) {
            $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
        }
    }
}
