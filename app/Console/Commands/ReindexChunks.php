<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\EmbeddingClient;
use App\Models\Chunk;
use App\Retrieval\Vector;
use Illuminate\Console\Command;

class ReindexChunks extends Command
{
    protected $signature = 'platform:reindex {--bot= : Limiter a un assistant (id)} {--all : Recalculer meme les extraits deja au bon modele}';

    protected $description = "Recalcule les vecteurs des extraits apres un changement de modele d'embeddings";

    public function handle(EmbeddingClient $embeddings): int
    {
        $model = $embeddings->model();
        $query = Chunk::withoutGlobalScopes()->with('document:id,title')
            ->when($this->option('bot'), fn ($q, $bot) => $q->where('bot_id', $bot))
            ->unless($this->option('all'), fn ($q) => $q->where(fn ($w) => $w->whereNull('embedding_model')->orWhere('embedding_model', '!=', $model)));

        $total = (clone $query)->count();
        $this->info("Modele actif : {$model} ({$total} extrait(s) a recalculer)");

        $bar = $this->output->createProgressBar($total);
        $query->orderBy('id')->chunkById((int) config('platform.rag.embed_batch'), function ($chunks) use ($embeddings, $model, $bar) {
            $vectors = $embeddings->embed($chunks->map(fn (Chunk $c) => $c->embeddingText($c->document?->title))->all());

            foreach ($chunks->values() as $i => $chunk) {
                $chunk->forceFill(['embedding' => Vector::encode($vectors[$i]), 'embedding_model' => $model])->save();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return self::SUCCESS;
    }
}
