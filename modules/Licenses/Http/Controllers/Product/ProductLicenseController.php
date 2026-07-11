<?php

namespace Modules\Licenses\Http\Controllers\Product;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Gateway\Domain\Contracts\ProductContext;
use Modules\Licenses\Application\DTO\LicenseValidationResult;
use Modules\Licenses\Application\Services\LicenseService;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Licenses\Http\Requests\Product\ActivateProductLicenseRequest;
use Modules\Licenses\Http\Requests\Product\DeactivateProductLicenseRequest;
use Modules\Licenses\Http\Requests\Product\ValidateProductLicenseRequest;
use Modules\Licenses\Http\Resources\ProductLicenseResource;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

/**
 * Product-facing license endpoints (behind the `product` guard, ADR 0004). A
 * product identifies itself by its API key and its licenses by key; it can only
 * ever act on licenses that belong to its own product. Ledger events from here
 * are attributed to the product actor.
 */
final class ProductLicenseController extends ApiController
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly ProductContext $product,
    ) {}

    /** Self-activate a device/domain slot on one of the product's licenses. */
    public function activate(ActivateProductLicenseRequest $request): JsonResponse
    {
        $license = $this->resolve($request);

        $activation = $this->licenses->activate(
            $license,
            IdentifierType::from((string) $request->string('identifier_type')),
            (string) $request->string('identifier'),
            $request->filled('name') ? (string) $request->string('name') : null,
            $this->product->productSlug(),
            'product',
        );

        return $this->respond($license, $activation, 201);
    }

    /** Online validation: report the license's current entitlement and record a heartbeat. */
    public function validate(ValidateProductLicenseRequest $request): JsonResponse
    {
        $license = $this->resolve($request);

        $identifier = $request->filled('identifier') ? (string) $request->string('identifier') : null;
        $activation = $this->licenses->heartbeat($license, $identifier);

        return $this->respond($license, $activation, 200);
    }

    /** Release one of the product's own activation slots by identifier. */
    public function deactivate(DeactivateProductLicenseRequest $request): JsonResponse
    {
        $license = $this->resolve($request);

        $activation = $license->activations()
            ->where('identifier', (string) $request->string('identifier'))
            ->first();

        if ($activation !== null) {
            $this->licenses->deactivate($activation, $this->product->productSlug(), 'product');
        }

        return $this->noContent();
    }

    private function resolve(Request $request): License
    {
        return $this->licenses->resolveForProduct(
            (string) $request->string('key'),
            (int) $this->product->productId(),
        );
    }

    private function respond(License $license, ?LicenseActivation $activation, int $status): JsonResponse
    {
        $license->loadCount('activeActivations');

        return ProductLicenseResource::make(new LicenseValidationResult($license, $activation))
            ->response()
            ->setStatusCode($status);
    }
}
