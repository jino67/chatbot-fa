<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une page d'un site en cours de lecture : l'état d'un crawl vit en base, pour pouvoir le reprendre tranche par tranche. */
class SourcePage extends Model
{
    use BelongsToWorkspace;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'bot_id', 'source_id', 'url', 'url_hash', 'status', 'depth', 'priority', 'http_status', 'title',
        'content', 'content_hash', 'products', 'note', 'fetched_at',
    ];

    protected $attributes = ['status' => self::PENDING, 'depth' => 0, 'priority' => 4];

    protected function casts(): array
    {
        return ['products' => 'array', 'fetched_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
