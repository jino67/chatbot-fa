<?php

namespace App\Ingestion\Catalog;

/** Un produit ou un service d'un catalogue, déjà normalisé (prix et disponibilité lisibles). */
final class CatalogProduct
{
    /**
     * @param  array<string,string>  $extras  colonnes supplémentaires (taille, couleur...)
     * @param  list<string>  $highlights  points forts annoncés sur la page du produit
     */
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
        public readonly ?string $image = null,
        public readonly ?string $orderLink = null,
        public readonly ?float $rating = null,
        public readonly array $highlights = [],
    ) {}

    /** @return array<string,mixed> pour être conservé dans la base entre deux tranches de lecture */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            category: $data['category'] ?? null,
            price: $data['price'] ?? null,
            salePrice: $data['salePrice'] ?? null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            availability: $data['availability'] ?? null,
            description: $data['description'] ?? null,
            reference: $data['reference'] ?? null,
            brand: $data['brand'] ?? null,
            link: $data['link'] ?? null,
            extras: (array) ($data['extras'] ?? []),
            image: $data['image'] ?? null,
            orderLink: $data['orderLink'] ?? null,
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            highlights: array_values((array) ($data['highlights'] ?? [])),
        );
    }

    /** Copie où les champs vides sont complétés par ceux de l'autre produit (même produit lu sur deux pages). */
    public function mergedWith(self $other): self
    {
        $a = $this->toArray();
        foreach ($other->toArray() as $key => $value) {
            if (($a[$key] === null || $a[$key] === [] || $a[$key] === '') && $value !== null && $value !== [] && $value !== '') {
                $a[$key] = $value;
            }
        }

        return self::fromArray($a);
    }
}
