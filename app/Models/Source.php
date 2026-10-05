<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Source extends Model
{
    use BelongsToWorkspace;

    public const TYPE_FILE = 'file';

    public const TYPE_IMAGE = 'image';

    public const TYPE_URL = 'url';

    public const TYPE_TEXT = 'text';

    public const TYPE_QA = 'qa';

    public const TYPE_FACEBOOK = 'facebook';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Lien enregistre (Facebook, Instagram) : le contenu reste a fournir par le client. */
    public const NEEDS_CONTENT = 'needs_content';

    protected $fillable = [
        'workspace_id', 'bot_id', 'type', 'name', 'status', 'payload', 'error', 'stats', 'progress', 'resync', 'last_synced_at',
    ];

    protected $attributes = [
        'status' => self::PENDING,
        'resync' => 'never',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'stats' => 'array',
            'progress' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Source $source) {
            $path = $source->payload['path'] ?? null;
            if ($path) {
                Storage::disk(config('platform.uploads.disk'))->delete($path);
            }
        });
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_FILE => 'Document',
            self::TYPE_IMAGE => 'Photo',
            self::TYPE_URL => 'Site web',
            self::TYPE_TEXT => 'Texte',
            self::TYPE_QA => 'Question / réponse',
            self::TYPE_FACEBOOK => (($this->payload['platform'] ?? 'facebook') === 'instagram') ? 'Instagram' : 'Page Facebook',
            default => $this->type,
        };
    }

    public function needsContent(): bool
    {
        return $this->status === self::NEEDS_CONTENT;
    }
}
