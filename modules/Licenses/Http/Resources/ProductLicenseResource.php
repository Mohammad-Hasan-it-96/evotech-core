<?php

namespace Modules\Licenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Licenses\Application\DTO\LicenseValidationResult;

/**
 * Product-facing view of a license after self-activation or online validation.
 * Exposes only what a product needs to gate its features — no company/subscription
 * internals — plus the activation the call concerns, when any.
 *
 * @mixin LicenseValidationResult
 */
class ProductLicenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $license = $this->license;

        return [
            'valid' => $license->isCurrentlyValid(),
            'status' => $license->status->value,
            'key' => $license->key,
            'product' => $license->subscription->plan->product->slug,
            'expires_at' => $license->expires_at?->toIso8601String(),
            'max_activations' => $license->max_activations,
            'activations_used' => $license->active_activations_count,
            'activation' => $this->activation !== null
                ? LicenseActivationResource::make($this->activation)
                : null,
        ];
    }
}
