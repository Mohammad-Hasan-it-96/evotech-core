<?php

namespace Modules\Gateway\Application\Support;

/**
 * The output of minting an API key: the one-time plaintext token to hand to the
 * caller, plus the non-secret prefix and the SHA-256 hash to persist.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        public string $plaintext,
        public string $prefix,
        public string $hash,
    ) {}
}
