<?php

namespace App\Models;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\Crawler\UrlRules;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un produit connu d'un assistant : son nom, son prix, sa disponibilité et sa photo. L'assistant le retrouve dans ses
 * extraits sous une référence courte (« P12 ») et peut en joindre la photo à sa réponse.
 */
class CatalogItem extends Model
{
    use BelongsToWorkspace;

    public const IMAGE_NONE = 'none';

    public const IMAGE_PENDING = 'pending';

    public const IMAGE_READY = 'ready';

    public const IMAGE_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'bot_id', 'source_id', 'item_key', 'name', 'category', 'price_text', 'amount', 'currency', 'availability',
        'description', 'url', 'image_url', 'image_path', 'image_status', 'image_fetched_at', 'times_shown',
    ];

    protected $attributes = ['image_status' => self::IMAGE_NONE, 'times_shown' => 0];

    protected function casts(): array
    {
        return ['amount' => 'float', 'image_fetched_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** Référence courte inscrite dans les extraits et reprise par l'assistant : « P12 ». */
    public function ref(): string
    {
        return 'P'.$this->id;
    }

    /** « P12 », « p12 » ou « 12 » : l'identifiant, ou null. */
    public static function idFromRef(string $ref): ?int
    {
        return preg_match('/^\s*P?(\d{1,9})\s*$/i', $ref, $m) ? (int) $m[1] : null;
    }

    /** Identité d'un produit entre deux lectures du site : l'adresse de sa page, à défaut son nom. */
    public static function keyFor(CatalogProduct $product): string
    {
        $identity = $product->link ? UrlRules::dedupeKey($product->link) : 'nom:'.trim(preg_replace('/\s+/', ' ', Text::fold($product->name)));

        return sha1($identity);
    }

    public function hasPhoto(): bool
    {
        return $this->image_url !== null && $this->image_status !== self::IMAGE_FAILED;
    }
}
