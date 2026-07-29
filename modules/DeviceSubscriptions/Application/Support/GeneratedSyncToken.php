<?php

namespace Modules\DeviceSubscriptions\Application\Support;

/**
 * The output of minting a device-sync token: the one-time plaintext to hand the
 * device, plus the non-secret prefix and the SHA-256 hash to persist on the seat.
 * Mirrors Gateway's GeneratedApiKey (ADR 0011 reuses the ADR 0004 credential shape).
 */
final readonly class GeneratedSyncToken
{
    public function __construct(
        public string $plaintext,
        public string $prefix,
        public string $hash,
    ) {}
}
