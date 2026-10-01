<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Cle d'API de l'offre developpeurs. Seule l'empreinte est stockee ; la cle en clair n'existe qu'a sa creation. */
class ApiKey extends Model
{
    use BelongsToWorkspace;

    public const PREFIX = 'kma_';

    protected $fillable = ['workspace_id', 'bot_id', 'name', 'prefix', 'key_hash', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return array{0: static, 1: string} la cle enregistree et la cle en clair (a montrer une seule fois) */
    public static function issue(int $workspaceId, int $botId, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);

        $key = static::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'bot_id' => $botId,
            'name' => $name,
            'prefix' => substr($plain, 0, 8),
            'key_hash' => self::hash($plain),
        ]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** La cle active correspondant a ce jeton, ou null (inconnue ou revoquee). */
    public static function findActive(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return static::withoutGlobalScopes()->where('key_hash', self::hash($plain))->whereNull('revoked_at')->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class)->withoutGlobalScopes();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
