<?php

namespace Modules\Gateway\Application\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Gateway\Application\DTO\MintedApiKey;
use Modules\Gateway\Application\Support\ApiKeyGenerator;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Products\Domain\Models\Product;

/**
 * Product API key use-cases (ADR 0004): minting (one-time plaintext), revocation,
 * listing, and token authentication for the `product` guard.
 */
final class ProductApiKeyService
{
    public function __construct(private readonly ApiKeyGenerator $generator) {}

    /** Mint a new key for a product. The plaintext is returned once and never stored. */
    public function mint(Product $product, string $name, ?Carbon $expiresAt = null): MintedApiKey
    {
        $generated = $this->generator->generate();

        $key = ProductApiKey::create([
            'product_id' => $product->id,
            'name' => $name,
            'prefix' => $generated->prefix,
            'key_hash' => $generated->hash,
            'expires_at' => $expiresAt,
        ]);

        return new MintedApiKey($key, $generated->plaintext);
    }

    /**
     * Resolve the active key a token authenticates as, or null. Refreshes
     * `last_used_at` on success. Lookup is by hash — a wrong token simply misses.
     */
    public function authenticate(string $token): ?ProductApiKey
    {
        $key = ProductApiKey::query()
            ->where('key_hash', $this->generator->hash($token))
            ->first();

        if ($key === null || ! $key->isActive()) {
            return null;
        }

        $key->forceFill(['last_used_at' => Carbon::now()])->save();

        return $key;
    }

    /** Terminally revoke a key. Idempotent. */
    public function revoke(ProductApiKey $key): ProductApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return $key;
    }

    /**
     * A product's keys, newest first. Bounded set (a product holds few keys).
     *
     * @return Collection<int, ProductApiKey>
     */
    public function forProduct(Product $product): Collection
    {
        return ProductApiKey::query()
            ->where('product_id', $product->id)
            ->latest()
            ->get();
    }
}
