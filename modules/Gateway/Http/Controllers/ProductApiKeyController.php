<?php

namespace Modules\Gateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Gateway\Http\Requests\StoreProductApiKeyRequest;
use Modules\Gateway\Http\Resources\ProductApiKeyResource;
use Modules\Products\Domain\Models\Product;

/**
 * Staff management of per-product API keys (ADR 0004). Minting returns the
 * plaintext token exactly once; thereafter only its metadata is retrievable.
 */
final class ProductApiKeyController extends ApiController
{
    public function __construct(private readonly ProductApiKeyService $keys) {}

    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductApiKeyResource::collection($this->keys->forProduct($product));
    }

    public function store(StoreProductApiKeyRequest $request, Product $product): JsonResponse
    {
        $minted = $this->keys->mint(
            $product,
            (string) $request->string('name'),
            $request->filled('expires_at') ? Carbon::parse((string) $request->string('expires_at')) : null,
        );

        return ProductApiKeyResource::minted($minted->apiKey, $minted->plaintext)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(ProductApiKey $apiKey): JsonResponse
    {
        $this->keys->revoke($apiKey);

        return $this->noContent();
    }
}
