<?php

namespace Modules\Gateway\Infrastructure\Auth;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Gateway\Domain\Contracts\ProductContext;
use Modules\Gateway\Domain\Models\ProductApiKey;

/**
 * Request-scoped {@see ProductContext} backed by the `product` guard. Reads the
 * authenticated {@see ProductApiKey} and exposes only its product's identity —
 * the concrete key never leaves the Gateway module.
 */
final class RequestProductContext implements ProductContext
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function isAuthenticated(): bool
    {
        return $this->key() !== null;
    }

    public function productId(): ?int
    {
        return $this->key()?->product_id;
    }

    public function productSlug(): ?string
    {
        return $this->key()?->product->slug;
    }

    private function key(): ?ProductApiKey
    {
        $user = $this->auth->guard('product')->user();

        return $user instanceof ProductApiKey ? $user : null;
    }
}
