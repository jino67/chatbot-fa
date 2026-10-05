<?php

namespace App\Services;

use App\Ingestion\Crawler\SafeHttp;
use App\Models\CatalogItem;
use App\Support\ImageTools;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Les photos des produits : lues sur le site du client, remises en JPEG (WhatsApp ne prend pas le webp), gardées sur nos
 * serveurs et servies par une adresse signée sous notre domaine. Le client final ne voit donc jamais l'adresse du site du
 * client, et WhatsApp trouve toujours une image qu'il sait lire. La lecture est faite au premier besoin, ou après la lecture
 * du site pour les premières photos (`warm`).
 */
class ProductImages
{
    private const MAX_BYTES = 6_000_000;

    private const MAX_SIDE = 1000;

    /** Prépare la photo (la télécharge si besoin) : vrai si elle est prête à être envoyée. */
    public function ensureLocal(CatalogItem $item): bool
    {
        if ($item->image_status === CatalogItem::IMAGE_READY && $item->image_path && $this->disk()->exists($item->image_path)) {
            return true;
        }
        // Une photo en échec est réessayée une fois par jour, pas à chaque message.
        if (! $item->image_url || ($item->image_status === CatalogItem::IMAGE_FAILED && $item->image_fetched_at?->gt(now()->subDay()))) {
            return false;
        }

        try {
            $res = SafeHttp::get($item->image_url, 3, self::MAX_BYTES);
            $image = $res['status'] === 200 && str_starts_with(strtolower($res['type']), 'image/') ? ImageTools::jpeg($res['body'], self::MAX_SIDE) : null;
        } catch (\Throwable) {
            $image = null;
        }

        if ($image === null) {
            $item->forceFill(['image_status' => CatalogItem::IMAGE_FAILED, 'image_fetched_at' => now()])->save();

            return false;
        }

        $path = 'catalog/'.$item->workspace_id.'/'.$item->id.'.jpg';
        $this->disk()->put($path, $image['bytes']);
        $item->forceFill(['image_path' => $path, 'image_status' => CatalogItem::IMAGE_READY, 'image_fetched_at' => now()])->save();

        return true;
    }

    public function contents(CatalogItem $item): ?string
    {
        return $this->ensureLocal($item) ? $this->disk()->get($item->image_path) : null;
    }

    /**
     * Prépare les premières photos d'un assistant dans le temps donné : les suivantes se préparent à la demande.
     *
     * @param  iterable<CatalogItem>  $items
     */
    public function warm(iterable $items, float $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        $ready = 0;

        foreach ($items as $item) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $ready += $this->ensureLocal($item) ? 1 : 0;
        }

        return $ready;
    }

    /** Adresse publique de la photo : signée, sans date d'expiration, liée à l'image actuelle (elle change si la photo change). */
    public function publicUrl(CatalogItem $item): ?string
    {
        if (! $item->image_url || $item->image_status === CatalogItem::IMAGE_FAILED) {
            return null;
        }

        $path = URL::signedRoute('media.product', ['item' => $item->id, 'v' => substr(md5($item->image_url), 0, 8)], absolute: false);
        $base = config('platform.whatsapp.twilio.public_base_url') ?: config('app.url');

        return rtrim((string) $base, '/').$path;
    }

    private function disk()
    {
        return Storage::disk(config('platform.uploads.disk'));
    }
}
