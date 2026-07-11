<?php

namespace Modules\Gateway\Domain\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Gateway\Database\Factories\ProductApiKeyFactory;
use Modules\Products\Domain\Models\Product;

/**
 * A revocable API key that authenticates a product's backend/device to the
 * platform (product-to-platform M2M auth — ADR 0004). Only a SHA-256 hash of the
 * token is persisted; the plaintext is shown once at creation. The key acts as
 * the authenticatable identity for the `product` guard.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class ProductApiKey extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @use HasFactory<ProductApiKeyFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'prefix',
        'key_hash',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Whether the key may currently authenticate (not revoked, not expired). */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function newFactory(): ProductApiKeyFactory
    {
        return ProductApiKeyFactory::new();
    }
}
