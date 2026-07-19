<?php

namespace Modules\Core\Infrastructure\Downloads;

use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;

/**
 * Safe default for the ReleaseDownloadLocator port: no downloads on offer.
 *
 * Bound by Core so a consumer never depends on the Downloads module being present
 * or enabled. "No links" is a state every caller already handles — a remote-config
 * with an empty `downloads` map is exactly what shipped before the Download Center
 * was wired in.
 */
final class NullReleaseDownloadLocator implements ReleaseDownloadLocator
{
    /**
     * @return array<string, string>
     */
    public function latestDownloadUrls(string $productSlug, ?string $channel = null): array
    {
        return [];
    }
}
