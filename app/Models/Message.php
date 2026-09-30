<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToWorkspace;

    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const AGENT = 'agent';

    public const SYSTEM = 'system';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'role', 'content', 'sources', 'meta', 'provider_message_id',
    ];

    protected function casts(): array
    {
        return ['sources' => 'array', 'meta' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Sous-requete a utiliser avec addSelect(['question' => ...]) sur la table messages :
     * renvoie le dernier message client AVANT chaque message. La table est aliasee ("prev") : sans alias,
     * "messages.id" designerait la table interne et non la ligne exterieure.
     */
    public static function previousUserContent()
    {
        return static::query()->withoutGlobalScopes()
            ->from('messages as prev')
            ->select('prev.content')
            ->whereColumn('prev.conversation_id', 'messages.conversation_id')
            ->whereColumn('prev.id', '<', 'messages.id')
            ->where('prev.role', self::USER)
            ->orderByDesc('prev.id')
            ->limit(1);
    }

    /** Message sans reponse : le bot n'a pas trouve l'information dans la base de connaissances. */
    public function isUngrounded(): bool
    {
        return $this->role === self::ASSISTANT && ($this->meta['grounded'] ?? true) === false;
    }

    /** Forme exposee au widget. */
    public function toWidget(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'at' => $this->created_at?->toIso8601String(),
            // Reponses rapides : le widget les affiche sous le dernier message de l'assistant.
            'suggestions' => $this->role === self::ASSISTANT ? array_values($this->meta['suggestions'] ?? []) : [],
            'sources' => collect($this->sources ?? [])
                ->map(fn ($s) => ['title' => $s['title'] ?? null, 'url' => $s['url'] ?? null])
                ->filter(fn ($s) => $s['url'])
                ->unique('url')
                ->values()
                ->all(),
        ];
    }
}
