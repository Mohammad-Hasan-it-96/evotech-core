<?php

namespace Modules\Downloads\Providers;

use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Downloads\Infrastructure\Locator\ReleaseDownloadUrlLocator;

/**
 * Downloads module: the Download Center (ADR 0008). Staff publish versioned
 * product releases + per-platform artifacts; products self-update from their
 * channel. Files live on a private disk and are delivered only via short-lived
 * signed URLs, each issue recorded to an immutable download ledger.
 */
final class DownloadsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Downloads';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('Config/downloads.php'), 'downloads');

        // Supplies Core's locator port, replacing the no-op default (§2.4) — so a
        // module wanting "the latest build" never depends on this one.
        $this->app->bind(ReleaseDownloadLocator::class, ReleaseDownloadUrlLocator::class);
    }
}
