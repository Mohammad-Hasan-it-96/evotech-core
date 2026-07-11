<?php

namespace Modules\Downloads\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Downloads\Domain\Models\DownloadEvent;

/**
 * @mixin DownloadEvent
 */
class DownloadEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
            'artifact' => $this->whenLoaded('artifact', fn (): array => [
                'id' => $this->artifact->uuid,
                'filename' => $this->artifact->filename,
                'platform' => $this->artifact->platform->value,
            ]),
        ];
    }
}
