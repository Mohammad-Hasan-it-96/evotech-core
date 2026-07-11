<?php

namespace Modules\Licenses\Application\DTO;

use Illuminate\Support\Carbon;

/**
 * A freshly signed offline license token (ADR 0005) and its metadata, returned to
 * the product for delivery to the device.
 */
final readonly class IssuedOfflineToken
{
    public function __construct(
        public string $token,
        public string $algorithm,
        public string $keyId,
        public Carbon $issuedAt,
        public Carbon $expiresAt,
    ) {}
}
