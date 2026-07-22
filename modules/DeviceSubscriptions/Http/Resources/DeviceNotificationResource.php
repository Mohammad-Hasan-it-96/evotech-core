<?php

namespace Modules\DeviceSubscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DeviceSubscriptions\Domain\Models\DeviceNotification;

/**
 * @mixin DeviceNotification
 */
class DeviceNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'app_name' => $this->app_name,
            'scope' => $this->scope,
            'active_only' => $this->active_only,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'recipients' => $this->recipients,
            'target_device_id' => $this->target_device_id,
            'sent_by_name' => $this->sent_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
