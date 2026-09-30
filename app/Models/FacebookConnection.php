<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookConnection extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'bot_id', 'source_id', 'page_id', 'page_name', 'page_url',
        'access_token', 'status', 'last_synced_at',
    ];

    protected $hidden = ['access_token'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'last_synced_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
