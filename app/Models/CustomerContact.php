<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un contact de l'équipe avec une personne inscrite (e-mail, WhatsApp, appel...). Réservé au super administrateur. */
class CustomerContact extends Model
{
    public const CHANNELS = [
        'email' => 'E-mail',
        'whatsapp' => 'WhatsApp',
        'call' => 'Appel',
        'push' => 'Notification',
        'meeting' => 'Rendez-vous',
        'note' => 'Note',
    ];

    public const OUTCOMES = [
        'replied' => 'A répondu',
        'interested' => 'Intéressé',
        'no_answer' => 'Sans réponse',
        'not_interested' => 'Pas intéressé',
        'converted' => 'A pris une offre',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
