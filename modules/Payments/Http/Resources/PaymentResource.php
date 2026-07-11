<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payments\Domain\Models\Payment;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at->toIso8601String(),
        ];
    }
}
