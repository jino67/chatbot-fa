<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'workspace_id', 'action', 'subject', 'meta', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Enregistre une action sensible ; ne doit jamais faire echouer l'action elle-meme. */
    public static function record(string $action, ?string $subject = null, array $meta = [], ?int $workspaceId = null): void
    {
        try {
            static::create([
                'user_id' => auth()->id(),
                'workspace_id' => $workspaceId ?? auth()->user()?->currentWorkspaceId(),
                'action' => $action,
                'subject' => $subject,
                'meta' => $meta ?: null,
                'ip' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
