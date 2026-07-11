<?php

namespace Modules\Gateway\Application\DTO;

use Modules\Gateway\Domain\Models\ProductApiKey;

/**
 * A freshly minted key: the persisted record plus its one-time plaintext token,
 * which is never stored and is returned to the caller exactly once.
 */
final readonly class MintedApiKey
{
    public function __construct(
        public ProductApiKey $apiKey,
        public string $plaintext,
    ) {}
}
