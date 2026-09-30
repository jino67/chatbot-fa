<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'bot_id', 'source_id', 'title', 'url', 'content', 'content_hash'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
