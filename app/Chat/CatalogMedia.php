<?php

namespace App\Chat;

use App\Models\Bot;
use App\Models\CatalogItem;
use App\Models\Conversation;
use App\Services\ProductImages;
use App\Support\Text;

/**
 * Quelles photos de produits accompagnent une réponse. L'assistant propose (marqueur [[PHOTO: P12]]) ; la plateforme
 * décide : seulement des produits de CET assistant, jamais deux fois la même photo dans une conversation (sauf si le
 * client la redemande), deux photos au plus par réponse, trois quand le client demande à voir, et rien du tout si le
 * propriétaire a coupé les photos.
 */
class CatalogMedia
{
    public const AUTO = 'auto';

    public const ASK = 'ask';

    public const OFF = 'off';

    public function __construct(private readonly ProductImages $images) {}

    /** Cet assistant a-t-il au moins un produit avec une photo ? (mis en cache : le prompt le demande à chaque message) */
    public function hasPhotos(Bot $bot): bool
    {
        return (bool) cache()->remember('catalog-photos:'.$bot->id, 60, fn () => CatalogItem::withoutGlobalScopes()
            ->where('bot_id', $bot->id)->whereNotNull('image_url')->where('image_status', '!=', CatalogItem::IMAGE_FAILED)->exists());
    }

    /**
     * @param  list<string>  $refs  références demandées par l'assistant, dans l'ordre
     * @return list<CatalogItem>
     */
    public function pick(Bot $bot, Conversation $conversation, array $refs, bool $asked): array
    {
        $policy = $bot->photoPolicy();
        if ($policy === self::OFF || ($policy === self::ASK && ! $asked)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(fn ($ref) => CatalogItem::idFromRef($ref), $refs))));
        if ($ids === []) {
            return [];
        }

        $items = CatalogItem::withoutGlobalScopes()->where('bot_id', $bot->id)->whereIn('id', $ids)->get()->keyBy('id');
        $shown = (array) ($conversation->meta['shown_items'] ?? []);
        $max = $asked ? 3 : 2;

        $picked = [];
        foreach ($ids as $id) {
            $item = $items[$id] ?? null;
            if (! $item || ! $item->hasPhoto() || (! $asked && in_array($id, $shown, true))) {
                continue;
            }
            $picked[] = $item;
            if (count($picked) >= $max) {
                break;
            }
        }

        if ($picked !== []) {
            $conversation->forceFill(['meta' => array_replace($conversation->meta ?? [], [
                'shown_items' => array_values(array_unique([...$shown, ...array_map(fn ($i) => $i->id, $picked)])),
            ])])->save();
            CatalogItem::withoutGlobalScopes()->whereIn('id', array_map(fn ($i) => $i->id, $picked))->increment('times_shown');
        }

        return $picked;
    }

    /**
     * Le client demande à voir un produit et l'assistant n'a pas joint de photo : on reprend les produits des extraits
     * qu'il vient de nommer dans sa réponse (sinon le premier extrait qui porte une photo).
     *
     * @param  list<\App\Retrieval\RetrievedChunk>  $chunks
     * @return list<string>
     */
    public function refsFromContext(array $chunks, string $answer): array
    {
        $found = [];
        foreach ($chunks as $chunk) {
            if (preg_match('/Produit : ([^\n]+)\n(?:.*\n)*?Photo : (P\d+)/u', $chunk->content, $m)) {
                $found[] = ['name' => trim($m[1]), 'ref' => $m[2]];
            }
        }

        $answer = Text::fold($answer);
        $named = array_values(array_filter($found, fn ($f) => str_contains($answer, Text::fold($f['name']))));

        return array_map(fn ($f) => $f['ref'], $named !== [] ? $named : array_slice($found, 0, 1));
    }

    /**
     * Ce que le message garde de chaque photo : de quoi la retrouver et l'afficher (l'adresse est recalculée à l'affichage).
     *
     * @param  list<CatalogItem>  $items
     * @return list<array{id:int, ref:string, name:string, caption:string}>
     */
    public function describe(array $items): array
    {
        return array_map(fn (CatalogItem $i) => [
            'id' => $i->id,
            'ref' => $i->ref(),
            'name' => $i->name,
            'caption' => trim($i->name.($i->price_text ? ' : '.$i->price_text : '')),
        ], $items);
    }

    /**
     * Adresse publique des photos d'un message (widget, aperçu du propriétaire), à partir de ce que le message a gardé.
     *
     * @param  list<array{id:int, ref?:string, name?:string, caption?:string}>  $media
     * @return list<array{id:int, name:string, caption:string, url:string}>
     */
    public function resolve(array $media): array
    {
        $items = CatalogItem::withoutGlobalScopes()->whereIn('id', array_column($media, 'id'))->get()->keyBy('id');
        $out = [];

        foreach ($media as $entry) {
            $item = $items[$entry['id']] ?? null;
            $url = $item ? $this->images->publicUrl($item) : null;
            if ($url) {
                $out[] = ['id' => $item->id, 'name' => $entry['name'] ?? $item->name, 'caption' => $entry['caption'] ?? $item->name, 'url' => $url];
            }
        }

        return $out;
    }
}
