<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

use Modules\DeviceSubscriptions\Domain\Models\DeviceJoinToken;

/**
 * The output of minting a join token: the persisted record plus the one-time
 * plaintext the owner device renders as a QR. The plaintext is never stored.
 */
final readonly class MintedJoinToken
{
    public function __construct(
        public DeviceJoinToken $token,
        public string $plaintext,
    ) {}
}
