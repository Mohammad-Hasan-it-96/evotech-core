<?php

namespace Modules\DeviceSubscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;

/**
 * @mixin DeviceApp
 */
final class DeviceAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            /*
             * `name` and `slug` are shown but are not editable (see
             * UpdateDeviceAppRequest). Surfacing them read-only is the point: an
             * operator needs to see which app a row is before changing its price,
             * and `name` is the exact string the shipped builds send.
             */
            'name' => $this->name,
            'slug' => $this->slug,

            'label' => $this->label,
            'trial_days' => $this->trial_days,
            'uses_shared_plans' => $this->uses_shared_plans,
            'plans_count' => $this->whenCounted('plans'),
        ];
    }
}
