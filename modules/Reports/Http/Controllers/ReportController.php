<?php

namespace Modules\Reports\Http\Controllers;

use Modules\Core\Http\Controllers\ApiController;
use Modules\Reports\Application\Services\ReportService;
use Modules\Reports\Http\Resources\OverviewReportResource;

/**
 * Read-only reporting endpoints (staff, `auth:sanctum`). Aggregations only — no
 * per-record data leaves here.
 */
final class ReportController extends ApiController
{
    public function __construct(private readonly ReportService $reports) {}

    public function overview(): OverviewReportResource
    {
        return OverviewReportResource::make($this->reports->overview());
    }
}
