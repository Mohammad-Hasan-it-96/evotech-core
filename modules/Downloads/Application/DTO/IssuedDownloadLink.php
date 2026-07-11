<?php

namespace Modules\Downloads\Application\DTO;

use Illuminate\Support\Carbon;

/**
 * A minted, short-lived signed download URL for an artifact (ADR 0008).
 */
final readonly class IssuedDownloadLink
{
    public function __construct(
        public string $url,
        public Carbon $expiresAt,
    ) {}
}
