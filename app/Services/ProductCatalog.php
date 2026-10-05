<?php

namespace App\Services;

use App\Ingestion\Catalog\CatalogProduct;
use App\Models\CatalogItem;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;

/**
 * Tient à jour les produits d'un assistant après chaque lecture de son site : un produit déjà connu garde son identifiant
 * (donc sa référence « P12 » reste valable dans les conversations en cours), un produit disparu du site disparaît du
 * catalogue, une photo qui change est relue.
 */
class ProductCatalog
{
    /**
     * @param  list<CatalogProduct>  $products
     * @return list<array{product:CatalogProduct, item:CatalogItem}>
     */
    public function sync(Source $source, array $products): array
    {
        $existing = CatalogItem::withoutGlobalScopes()->where('bot_id', $source->bot_id)->get()->keyBy('item_key');
        $kept = [];
        $out = [];

        foreach ($products as $product) {
            $key = CatalogItem::keyFor($product);
            $item = $existing[$key] ?? new CatalogItem(['workspace_id' => $source->workspace_id, 'bot_id' => $source->bot_id, 'item_key' => $key]);

            $imageChanged = $item->image_url !== $product->image;
            $item->fill([
                'source_id' => $source->id,
                'name' => mb_substr($product->name, 0, 160),
                'category' => $product->category ? mb_substr($product->category, 0, 80) : null,
                'price_text' => $product->price ? mb_substr($product->price, 0, 80) : null,
                'amount' => $product->amount,
                'currency' => $product->currency,
                'availability' => $product->availability ? mb_substr($product->availability, 0, 60) : null,
                'description' => $product->description,
                'url' => $product->link,
                'image_url' => $product->image,
            ]);

            if ($imageChanged) {
                $this->forgetImage($item);
                $item->image_status = $product->image ? CatalogItem::IMAGE_PENDING : CatalogItem::IMAGE_NONE;
            }

            $item->save();
            $kept[] = $item->id;
            $out[] = ['product' => $product, 'item' => $item];
        }

        // Les produits de cette source qui ne figurent plus sur le site quittent le catalogue.
        CatalogItem::withoutGlobalScopes()->where('bot_id', $source->bot_id)->where('source_id', $source->id)
            ->whereNotIn('id', $kept === [] ? [0] : $kept)->get()
            ->each(function (CatalogItem $gone) {
                $this->forgetImage($gone);
                $gone->delete();
            });

        cache()->forget('catalog-photos:'.$source->bot_id);

        return $out;
    }

    /** Efface la photo gardée sur nos serveurs (elle sera relue au besoin). */
    public function forgetImage(CatalogItem $item): void
    {
        if ($item->image_path) {
            Storage::disk(config('platform.uploads.disk'))->delete($item->image_path);
        }
        $item->image_path = null;
    }
}
