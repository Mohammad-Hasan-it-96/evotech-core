<?php

namespace Modules\Downloads\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

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
    }
}
