<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Products\Application\Services\ProductCatalogService;
use Modules\Products\Domain\Models\Product;
use Modules\Products\Http\Resources\ProductResource;

/**
 * Public, read-only product catalog. Feeds both the marketing website and the
 * dashboard, so no authentication is required (still rate-limited via the api group).
 */
final class ProductController extends ApiController
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    public function index(): AnonymousResourceCollection
    {
        return ProductResource::collection($this->catalog->activeCatalog());
    }

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($this->catalog->loadActivePlans($product));
    }
}
