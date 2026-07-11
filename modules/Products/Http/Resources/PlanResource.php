<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Products\Domain\Models\Plan;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period->value,
            'features' => $this->features,
            'is_popular' => $this->is_popular,
        ];
    }
}
