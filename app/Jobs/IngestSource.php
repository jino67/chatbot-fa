<?php

namespace App\Jobs;

use App\Ingestion\IngestionPipeline;
use App\Models\Source;
use App\Support\Runtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Indexe (ou re-indexe) une source de connaissances. Les erreurs sont portees par le statut de la source. */
class IngestSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $sourceId) {}

    public function handle(IngestionPipeline $pipeline): void
    {
        // Un crawl peut depasser max_execution_time quand la file est en mode sync (dev).
        @set_time_limit(0);

        $source = Source::withoutGlobalScopes()->with('workspace')->find($this->sourceId);

        if ($source) {
            // Une source « en attente » (nouvelle, ou « Relire ») démarre une lecture neuve ; une source « en cours » reprend la sienne.
            if ($source->status === Source::PENDING && $source->progress) {
                $source->forceFill(['progress' => null])->save();
            }

            // Même verrou que la page ouverte et le planificateur : un site n'est jamais lu deux fois en même temps.
            $lock = Cache::lock('crawl-source-'.$source->id, 900);
            if (! $lock->get()) {
                return;
            }

            try {
                $pipeline->run($source, Runtime::crawlSeconds());
            } finally {
                $lock->release();
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Source::withoutGlobalScopes()->whereKey($this->sourceId)->update([
            'status' => Source::FAILED,
            'error' => 'Le traitement a ete interrompu : '.$e->getMessage(),
        ]);
    }
}
