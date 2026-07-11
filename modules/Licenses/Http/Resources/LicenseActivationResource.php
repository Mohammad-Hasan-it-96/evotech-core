<?php

namespace Modules\Licenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Licenses\Domain\Models\LicenseActivation;

/**
 * @mixin LicenseActivation
 */
class LicenseActivationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'identifier_type' => $this->identifier_type->value,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'is_active' => $this->isActive(),
            'activated_at' => $this->activated_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
