<?php

namespace App\Jobs;

use App\Ingestion\IngestionPipeline;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            $pipeline->run($source);
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
