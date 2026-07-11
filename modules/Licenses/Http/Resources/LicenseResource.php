<?php

namespace Modules\Licenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Licenses\Domain\Models\License;

/**
 * @mixin License
 */
class LicenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'key' => $this->key,
            'status' => $this->status->value,
            'max_activations' => $this->max_activations,
            'activations_used' => $this->whenCounted('activeActivations'),
            'activations' => LicenseActivationResource::collection($this->whenLoaded('activations')),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_valid' => $this->isCurrentlyValid(),
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->uuid,
                'name' => $this->company->name,
            ]),
            'subscription' => $this->whenLoaded('subscription', fn (): array => [
                'id' => $this->subscription->uuid,
                'status' => $this->subscription->status->value,
                'product' => $this->subscription->relationLoaded('plan') ? [
                    'slug' => $this->subscription->plan->product->slug,
                    'name' => $this->subscription->plan->product->name,
                ] : null,
            ]),
        ];
    }
}
