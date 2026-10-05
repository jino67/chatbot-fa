<?php

namespace App\Ingestion\Crawler;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Extractors\HtmlToText;
use App\Ingestion\Extractors\ProductScanner;
use App\Support\Text;

/** Une page lue : son texte, ses liens, et les produits qu'elle présente (avec leur prix, leur photo et leur lien). */
final class PageReader
{
    public function __construct(private readonly HtmlToText $html) {}

    /**
     * @return array{title:?string, text:string, links:list<string>, products:list<CatalogProduct>, main:?CatalogProduct, listing:bool, canonical:?string}
     */
    public function read(string $html, string $url, string $currency = 'XOF'): array
    {
        $scanner = new ProductScanner($currency);
        $page = $this->html->convert($html, $url, $scanner);

        $main = $scanner->main();
        $products = $scanner->products();

        // La fiche d'un produit seul se désigne elle-même : son adresse est celle de la page lue.
        if ($main && $main->link === null) {
            $withLink = new CatalogProduct(...[...$main->toArray(), 'link' => $url]);
            $products = array_map(fn (CatalogProduct $p) => Text::fold($p->name) === Text::fold($main->name) ? $withLink : $p, $products);
            $main = $withLink;
        }

        return [
            'title' => $page['title'],
            'text' => $page['text'],
            'links' => $page['links'],
            'products' => $products,
            'main' => $main,
            'listing' => $scanner->isListing(),
            'canonical' => $scanner->canonical(),
        ];
    }
}
