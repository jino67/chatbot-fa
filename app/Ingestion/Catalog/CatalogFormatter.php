<?php

namespace App\Ingestion\Catalog;

use App\Ingestion\Catalog\Money as M;
use App\Support\Text;

/**
 * Produits normalisés -> texte Markdown que le découpage et la recherche exploitent bien : une phrase par produit
 * (le nom d'abord), une section par catégorie (le fil d'Ariane accompagne chaque extrait), et un paragraphe d'ensemble
 * qui répond à « que vendez-vous ? » et « dans quelle fourchette de prix ? ».
 *
 * Le même formateur servira à l'import direct du catalogue Meta (voir docs/CATALOGUE.md, étape 2).
 */
class CatalogFormatter
{
    /** @param list<CatalogProduct> $products */
    public function format(array $products, string $title): string
    {
        if ($products === []) {
            return '';
        }

        $groups = [];
        foreach ($products as $product) {
            $groups[$product->category ?? ''][] = $product;
        }

        $text = $this->overview($products, $groups, $title)."\n\n";

        foreach ($groups as $category => $items) {
            if ($category !== '') {
                $text .= '## '.$this->inline($category)."\n\n";
            } elseif (count($groups) > 1) {
                $text .= "## Autres produits\n\n";
            }
            foreach ($items as $product) {
                $text .= $this->line($product)."\n\n";
            }
        }

        return trim($text);
    }

    /**
     * @param  list<CatalogProduct>  $products
     * @param  array<string,list<CatalogProduct>>  $groups
     */
    private function overview(array $products, array $groups, string $title): string
    {
        $count = count($products);
        $sentence = 'Catalogue « '.$this->inline($title).' » : '.$count.' '.($count > 1 ? 'produits ou services' : 'produit ou service');

        $named = array_filter($groups, fn ($items, $name) => $name !== '', ARRAY_FILTER_USE_BOTH);
        if (count($named) > 1) {
            $parts = [];
            foreach (array_slice($named, 0, 8, true) as $name => $items) {
                $parts[] = $this->inline((string) $name).' : '.count($items);
            }
            $sentence .= ' en '.count($named).' catégories ('.implode(', ', $parts).(count($named) > 8 ? ', ...' : '').')';
        }

        $sentence .= '.';

        // Fourchette de prix dans la devise la plus courante.
        $byCurrency = [];
        foreach ($products as $product) {
            if ($product->amount !== null && $product->currency !== null) {
                $byCurrency[$product->currency][] = $product->amount;
            }
        }
        if ($byCurrency !== []) {
            uasort($byCurrency, fn ($a, $b) => count($b) <=> count($a));
            $code = array_key_first($byCurrency);
            $amounts = $byCurrency[$code];
            $low = min($amounts);
            $high = max($amounts);
            $sentence .= $low === $high
                ? ' Prix : '.M::format($low, $code).'.'
                : ' Prix de '.M::format($low, $code).' à '.M::format($high, $code).'.';
        }

        return $sentence;
    }

    private function line(CatalogProduct $p): string
    {
        $head = $this->name($p->name).($p->reference ? ' (réf. '.$this->inline($p->reference).')' : '');
        $bits = [];

        if ($p->salePrice && $p->price) {
            $bits[] = $p->salePrice.' en promotion (prix normal : '.$p->price.')';
        } elseif ($p->price || $p->salePrice) {
            $bits[] = $p->price ?? $p->salePrice;
        }
        if ($p->availability) {
            $bits[] = $p->availability;
        }
        if ($p->brand) {
            $bits[] = 'Marque : '.$this->inline($p->brand);
        }
        foreach ($p->extras as $label => $value) {
            $bits[] = $this->inline((string) $label).' : '.$this->inline($value);
        }
        if ($p->description) {
            $bits[] = 'Description : '.$this->inline(Text::limit($p->description, 400));
        }
        if ($p->link) {
            $bits[] = 'Lien : '.$this->inline($p->link);
        }

        $bits = array_map(fn ($bit) => rtrim($bit, " .;"), $bits);
        $line = $head.($bits ? ' : '.implode('. ', $bits) : '').'.';

        return Text::limit($line, 900);
    }

    /** Un nom de produit ne doit jamais être lu comme un titre Markdown. */
    private function name(string $name): string
    {
        return ltrim($this->inline($name), '#>*-+ ');
    }

    private function inline(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
