<?php

namespace App\Console\Commands;

use App\Ingestion\IngestionPipeline;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reprend la lecture des sites que personne ne fait avancer : le client a fermé la page, ou l'hébergeur a coupé la
 * requête. L'état de la lecture est en base : on repart de la dernière page lue.
 */
class ContinueSources extends Command
{
    protected $signature = 'sources:continue';

    protected $description = 'Reprend la lecture des sites restés en cours';

    public function handle(IngestionPipeline $pipeline): int
    {
        $stale = Source::withoutGlobalScopes()->with('workspace')
            ->where('type', Source::TYPE_URL)
            ->whereIn('status', [Source::PENDING, Source::PROCESSING])
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->get();

        $resumed = 0;
        foreach ($stale as $source) {
            // Le verrou est celui de la page ouverte : on ne lit jamais deux fois le même site en même temps.
            $lock = Cache::lock('crawl-source-'.$source->id, 300);
            if (! $lock->get()) {
                continue;
            }

            try {
                if ($source->status === Source::PENDING && $source->progress) {
                    $source->forceFill(['progress' => null])->save();
                }
                $pipeline->run($source, 240.0);
                $resumed++;
            } finally {
                $lock->release();
            }
        }

        $this->info($resumed.' lecture(s) reprise(s).');

        return self::SUCCESS;
    }
}
