<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'bot_id', 'source_id', 'document_id', 'position', 'heading_path',
        'content', 'token_count', 'embedding', 'embedding_model',
    ];

    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** Texte envoye au moteur d'embeddings : le fil d'Ariane du titre aide la recherche. */
    public function embeddingText(?string $documentTitle = null): string
    {
        $prefix = Text::breadcrumb($documentTitle, $this->heading_path);

        return $prefix === '' ? $this->content : $prefix."\n".$this->content;
    }
}
