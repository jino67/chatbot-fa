<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/** Recalcule les vecteurs des extraits apres un changement de moteur d'embeddings (voir platform:reindex). */
class ReindexEmbeddings implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function handle(): void
    {
        @set_time_limit(0);
        Artisan::call('platform:reindex');
    }
}
