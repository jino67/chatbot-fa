<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un compte Google, Apple, Microsoft ou Facebook relié à un utilisateur. Aucun jeton n'est conservé. Pas de
 * BelongsToWorkspace : le rattachement se fait par l'utilisateur, et seule la personne connectée (ou la supervision
 * de la plateforme) lit ses propres liaisons.
 */
class SocialAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
