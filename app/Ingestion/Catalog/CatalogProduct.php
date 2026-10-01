<?php

namespace App\Ingestion\Catalog;

/** Un produit ou un service d'un catalogue, déjà normalisé (prix et disponibilité lisibles). */
final class CatalogProduct
{
    /** @param array<string,string> $extras colonnes supplémentaires (taille, couleur...) */
    public function __construct(
        public readonly string $name,
        public readonly ?string $category = null,
        public readonly ?string $price = null,
        public readonly ?string $salePrice = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $availability = null,
        public readonly ?string $description = null,
        public readonly ?string $reference = null,
        public readonly ?string $brand = null,
        public readonly ?string $link = null,
        public readonly array $extras = [],
    ) {}
}
