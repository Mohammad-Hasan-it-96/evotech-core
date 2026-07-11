<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payments\Domain\Models\Invoice;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'payments_count' => $this->whenCounted('payments'),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->uuid,
                'name' => $this->company->name,
            ]),
            'subscription' => $this->whenLoaded('subscription', function (): ?array {
                $subscription = $this->subscription;

                if ($subscription === null) {
                    return null;
                }

                return [
                    'id' => $subscription->uuid,
                    'status' => $subscription->status->value,
                    'product' => $subscription->relationLoaded('plan') ? [
                        'slug' => $subscription->plan->product->slug,
                        'name' => $subscription->plan->product->name,
                    ] : null,
                ];
            }),
        ];
    }
}
