<?php

namespace Modules\DeviceSubscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Staff-facing representation of a device subscription (platform envelope). Used by
 * the versioned admin endpoints — the device/app-facing endpoints keep the legacy
 * raw shapes instead (ADR 0010).
 *
 * @mixin DeviceSubscription
 */
class DeviceSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'app_name' => $this->app_name,
            'device_id' => $this->device_id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'is_verified' => $this->is_verified,
            'is_active' => $this->isActive(),
            'is_trial' => $this->isOnTrial(),
            'plan_id' => $this->plan_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'trial_expires_at' => $this->trial_expires_at?->toIso8601String(),
            // The open purchase intent the operator acts on (see the console).
            'status' => $this->status,
            'requested_plan' => $this->requested_plan,
            'contact_method' => $this->contact_method,
            'stars' => $this->stars,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
