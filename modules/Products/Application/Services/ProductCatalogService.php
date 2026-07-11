<?php

namespace Modules\Products\Application\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Product;

final class ProductCatalogService
{
    /**
     * Active products with their active plans, ordered for display.
     *
     * @return Collection<int, Product>
     */
    public function activeCatalog(): Collection
    {
        return Product::query()
            ->where('status', ProductStatus::Active)
            ->with('activePlans')
            ->orderBy('sort_order')
            ->get();
    }

    public function loadActivePlans(Product $product): Product
    {
        return $product->load('activePlans');
    }
}
