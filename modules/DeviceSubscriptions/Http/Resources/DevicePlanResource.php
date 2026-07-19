<?php

namespace Modules\DeviceSubscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;

/**
 * The admin view of a plan — distinct from the legacy `getPlans` payload, which is
 * pinned in DevicePlan::toLegacyArray().
 *
 * @mixin DevicePlan
 */
final class DevicePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Two identifiers, and confusing them is a live hazard:
             *
             *   id  — the uuid, addresses this row for editing.
             *   key — the plan_key, what the app sends back and what
             *         device_subscriptions.plan_id stores.
             *
             * The activate dialog must send `key`, never `id`: an id the device's
             * catalog does not define resolves to a 0-month term.
             */
            'id' => $this->uuid,
            'key' => $this->plan_key,

            'app_id' => $this->whenLoaded('app', fn () => $this->app?->uuid),
            'is_shared' => $this->device_app_id === null,

            'title' => $this->title,
            'description' => $this->description,
            'duration_months' => $this->duration_months,
            'price' => (float) $this->price,
            'price_after_discount' => $this->price_after_discount === null
                ? null
                : (float) $this->price_after_discount,
            'enabled' => $this->enabled,
            'recommended' => $this->recommended,
            'sort_order' => $this->sort_order,
        ];
    }
}
