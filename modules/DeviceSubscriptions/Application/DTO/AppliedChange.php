<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

/**
 * The server's verdict on one pushed change (ADR 0011). `seq` is the server
 * cursor assigned to the row; `duplicate` is true when the exact edit (same row +
 * authored_hlc) was already recorded, so this push was a no-op that returned the
 * original seq (§F2 idempotency).
 */
final readonly class AppliedChange
{
    public function __construct(
        public string $rowUuid,
        public string $authoredHlc,
        public int $seq,
        public bool $duplicate,
    ) {}
}
