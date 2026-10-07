<?php

namespace Modules\DeviceSubscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DeviceSubscriptions\Domain\Models\DeviceStatement;

/**
 * Staff view of a shared statement link (ADR 0013): enough to identify it and stop
 * it, and no more. Amounts and entries stay out of the console; only the holder of
 * the link sees those. The token is unrecoverable (stored hashed), so there is no
 * URL here either.
 *
 * @mixin DeviceStatement
 */
class DeviceStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->payload;
        $entries = $payload['entries'] ?? [];

        return [
            'id' => $this->uuid,
            'customer_name' => is_string($payload['customer_name'] ?? null) ? $payload['customer_name'] : '',
            'currency' => is_string($payload['currency'] ?? null) ? $payload['currency'] : '',
            'entries_count' => is_array($entries) ? count($entries) : 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
