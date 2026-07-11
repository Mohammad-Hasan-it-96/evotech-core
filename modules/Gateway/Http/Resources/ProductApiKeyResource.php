<?php

namespace Modules\Gateway\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Gateway\Domain\Models\ProductApiKey;

/**
 * @mixin ProductApiKey
 */
class ProductApiKeyResource extends JsonResource
{
    /** One-time plaintext token, present only in the mint response. */
    public ?string $plaintext = null;

    /** Build a mint response that includes the one-time plaintext `key`. */
    public static function minted(ProductApiKey $apiKey, string $plaintext): self
    {
        $resource = new self($apiKey);
        $resource->plaintext = $plaintext;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'prefix' => $this->prefix,
            // Only ever populated once, at creation — never re-derivable afterwards.
            'key' => $this->when($this->plaintext !== null, $this->plaintext),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
