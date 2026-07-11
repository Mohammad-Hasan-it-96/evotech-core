<?php

namespace Modules\Gateway\Domain\Contracts;

/**
 * The authenticated product's identity for the current request, exposed to other
 * modules so they never touch the Gateway's Eloquent models directly (§2.1).
 * Resolved from the `product` guard. All accessors return null when no product is
 * authenticated.
 */
interface ProductContext
{
    /** True when a product is authenticated on the current request. */
    public function isAuthenticated(): bool;

    /** Internal id of the authenticated product, or null. */
    public function productId(): ?int;

    /** Public slug of the authenticated product, or null. */
    public function productSlug(): ?string;
}
