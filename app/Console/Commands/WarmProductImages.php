<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Services\ProductImages;
use Illuminate\Console\Command;

/**
 * Prépare les photos de produits lues sur les sites (téléchargées, remises en JPEG) avant qu'un client les demande :
 * la première photo d'une conversation part ainsi sans attente. Quelques dizaines à chaque passage, les autres au suivant.
 */
class WarmProductImages extends Command
{
    protected $signature = 'catalog:warm-images {--limit=30}';

    protected $description = 'Prépare les photos des produits en attente';

    public function handle(ProductImages $images): int
    {
        $pending = CatalogItem::withoutGlobalScopes()->where('image_status', CatalogItem::IMAGE_PENDING)
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();

        $ready = $images->warm($pending, 100.0);

        $this->info($ready.' photo(s) prête(s) sur '.$pending->count().'.');

        return self::SUCCESS;
    }
}
