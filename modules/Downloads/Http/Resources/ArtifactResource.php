<?php

namespace Modules\Downloads\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Downloads\Domain\Models\Artifact;

/**
 * @mixin Artifact
 */
class ArtifactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'platform' => $this->platform->value,
            'filename' => $this->filename,
            'size' => $this->size,
            'checksum_sha256' => $this->checksum_sha256,
            'content_type' => $this->content_type,
            'download_count' => $this->download_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
