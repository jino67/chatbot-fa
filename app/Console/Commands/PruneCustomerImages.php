<?php

namespace App\Console\Commands;

use App\Chat\CustomerImages;
use Illuminate\Console\Command;

/** Efface les photos des clients conservées au-delà de la durée prévue ; la description écrite de chaque photo reste. */
class PruneCustomerImages extends Command
{
    protected $signature = 'images:prune';

    protected $description = 'Efface les photos de clients conservées trop longtemps';

    public function handle(CustomerImages $images): int
    {
        $this->info($images->prune().' photo(s) effacée(s) (au-delà de '.config('platform.vision.retention_days').' jours).');

        return self::SUCCESS;
    }
}
