<?php

namespace Modules\Licenses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Licenses\Application\DTO\IssuedOfflineToken;

/**
 * @mixin IssuedOfflineToken
 */
class OfflineTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'algorithm' => $this->algorithm,
            'key_id' => $this->keyId,
            'issued_at' => $this->issuedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
