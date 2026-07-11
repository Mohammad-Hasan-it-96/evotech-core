<?php

namespace Modules\Licenses\Infrastructure\Reporting;

use Modules\Licenses\Domain\Contracts\LicenseStats;
use Modules\Licenses\Domain\Enums\LicenseStatus;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;

final class EloquentLicenseStats implements LicenseStats
{
    public function total(): int
    {
        return License::query()->count();
    }

    public function active(): int
    {
        return License::query()->where('status', LicenseStatus::Active->value)->count();
    }

    public function activeActivations(): int
    {
        return LicenseActivation::query()->whereNull('revoked_at')->count();
    }
}
