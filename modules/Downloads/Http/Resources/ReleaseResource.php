<?php

namespace Modules\Downloads\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Downloads\Domain\Models\Release;

/**
 * @mixin Release
 */
class ReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'version' => $this->version,
            'channel' => $this->channel->value,
            'name' => $this->name,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'artifacts_count' => $this->whenCounted('artifacts'),
            'artifacts' => ArtifactResource::collection($this->whenLoaded('artifacts')),
            'product' => $this->whenLoaded('product', fn (): array => [
                'slug' => $this->product->slug,
                'name' => $this->product->name,
            ]),
        ];
    }
}
