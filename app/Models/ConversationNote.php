<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une note, un signalement, un « examinée » ou un résumé IA de l'équipe sur une conversation. Réservé au super administrateur :
 * volontairement SANS le trait BelongsToWorkspace, et jamais lu par une page cliente (TenantIsolationTest).
 */
class ConversationNote extends Model
{
    public const NOTE = 'note';

    public const FLAG = 'flag';

    public const REVIEW = 'review';

    public const SUMMARY = 'summary';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
