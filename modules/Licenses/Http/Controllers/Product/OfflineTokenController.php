<?php

namespace Modules\Licenses\Http\Controllers\Product;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Gateway\Domain\Contracts\ProductContext;
use Modules\Licenses\Application\Services\LicenseService;
use Modules\Licenses\Application\Services\OfflineTokenService;
use Modules\Licenses\Http\Requests\Product\IssueOfflineTokenRequest;
use Modules\Licenses\Http\Resources\OfflineTokenResource;

/**
 * Issues signed offline license tokens to products (ADR 0005). A product may
 * request a token only for its own license and only for a device that is already
 * an active activation of it; the token is signed and clamped to the license's
 * lifetime so a controller can verify entitlement offline.
 */
final class OfflineTokenController extends ApiController
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly OfflineTokenService $tokens,
        private readonly ProductContext $product,
    ) {}

    public function issue(IssueOfflineTokenRequest $request): JsonResponse
    {
        $license = $this->licenses->resolveForProduct(
            (string) $request->string('key'),
            (int) $this->product->productId(),
        );

        if (! $license->isCurrentlyValid()) {
            throw ValidationException::withMessages([
                'key' => __('This license is not active.'),
            ]);
        }

        $identifier = (string) $request->string('identifier');
        $activation = $license->activeActivations()->where('identifier', $identifier)->first();

        if ($activation === null) {
            throw ValidationException::withMessages([
                'identifier' => __('This device is not an active activation of the license.'),
            ]);
        }

        // Requesting a token is an online check-in.
        $activation->forceFill(['last_seen_at' => Carbon::now()])->save();

        $issued = $this->tokens->issue($license, $activation, $this->product->productSlug(), 'product');

        return OfflineTokenResource::make($issued)
            ->response()
            ->setStatusCode(201);
    }
}
