<?php

namespace Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;

        if (! $notification instanceof DatabaseNotification) {
            return [];
        }

        $data = $notification->getAttribute('data');
        $readAt = $notification->getAttribute('read_at');
        $createdAt = $notification->getAttribute('created_at');

        $type = is_array($data) && isset($data['type']) && is_string($data['type'])
            ? $data['type']
            : 'notification';

        return [
            'id' => $notification->getKey(),
            'type' => $type,
            'data' => $data,
            'read' => $readAt !== null,
            'read_at' => $readAt instanceof Carbon ? $readAt->toIso8601String() : null,
            'created_at' => $createdAt instanceof Carbon ? $createdAt->toIso8601String() : null,
        ];
    }
}
