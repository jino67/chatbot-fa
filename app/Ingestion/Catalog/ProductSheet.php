<?php

namespace App\Ingestion\Catalog;

use App\Ingestion\ExtractedDocument;
use App\Support\Text;

/**
 * La fiche écrite d'un produit : un petit texte autonome (nom, catégorie, prix, disponibilité, description, photo)
 * qui tient dans un seul extrait. Nom et prix restent toujours dans le même extrait : l'assistant ne peut plus coller
 * le prix d'un produit sur un autre.
 */
final class ProductSheet
{
    public static function document(CatalogProduct $p, ?string $ref): ExtractedDocument
    {
        $name = trim(preg_replace('/\s+/u', ' ', $p->name));
        $lines = ['# '.ltrim($name, '#> '), '', 'Produit : '.$name];

        if ($p->category) {
            $lines[] = 'Catégorie : '.$p->category;
        }
        if ($p->brand) {
            $lines[] = 'Marque : '.$p->brand;
        }

        $lines[] = $p->price !== null
            ? 'Prix : '.($p->salePrice ? $p->salePrice.' (promotion, prix normal : '.$p->price.')' : $p->price)
            : 'Prix : non indiqué sur le site (à confirmer avec l\'équipe)';

        if ($p->availability) {
            $lines[] = 'Disponibilité : '.($p->availability === 'Rupture de stock' ? 'actuellement en rupture de stock' : $p->availability);
        }
        if ($p->rating) {
            $lines[] = 'Note des clients : '.str_replace('.', ',', rtrim(rtrim(number_format($p->rating, 1), '0'), '.')).' sur 5';
        }
        if ($p->description) {
            $lines[] = 'Description : '.rtrim(Text::limit($p->description, 600), '. ').'.';
        }
        if ($p->highlights !== []) {
            $lines[] = 'Points forts : '.implode(' ; ', array_map(fn ($h) => rtrim(trim($h), '. '), $p->highlights)).'.';
        }
        foreach ($p->extras as $label => $value) {
            $lines[] = $label.' : '.$value;
        }
        if ($p->reference) {
            $lines[] = 'Référence : '.$p->reference;
        }
        if ($p->link) {
            $lines[] = 'Page du produit : '.$p->link;
        }
        if ($p->orderLink) {
            $lines[] = 'Commander en ligne : '.$p->orderLink;
        }
        if ($p->image && $ref) {
            $lines[] = 'Photo : '.$ref;
        }

        return new ExtractedDocument($name, implode("\n", $lines), $p->link, ['product_ref' => $ref]);
    }

    /**
     * « Que vendez-vous ? » : l'aperçu de tout le catalogue, par catégorie, avec la fourchette de prix.
     *
     * @param  list<CatalogProduct>  $products
     */
    public static function overview(array $products, string $title): ?ExtractedDocument
    {
        if (count($products) < 2) {
            return null;
        }

        $text = (new CatalogFormatter)->format($products, $title);

        return $text !== '' ? new ExtractedDocument('Catalogue : '.$title, $text, null, ['products' => count($products)]) : null;
    }
}
