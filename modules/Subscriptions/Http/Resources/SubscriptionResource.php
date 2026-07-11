<?php

namespace Modules\Subscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'identifier' => $this->identifier_type === null ? null : [
                'type' => $this->identifier_type->value,
                'value' => $this->identifier_value,
            ],
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'auto_renew' => $this->auto_renew,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'is_active' => $this->isCurrentlyActive(),
            'days_remaining' => $this->daysRemaining(),
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->uuid,
                'name' => $this->company->name,
            ]),
            'plan' => $this->whenLoaded('plan', fn (): array => [
                'id' => $this->plan->uuid,
                'name' => $this->plan->name,
                'billing_period' => $this->plan->billing_period->value,
                'product' => [
                    'slug' => $this->plan->product->slug,
                    'name' => $this->plan->product->name,
                ],
            ]),
        ];
    }

    private function daysRemaining(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return intdiv($this->ends_at->getTimestamp() - Carbon::now()->getTimestamp(), 86400);
    }
}
