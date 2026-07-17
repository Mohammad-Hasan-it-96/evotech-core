<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DeviceSubscriptions\Application\Services\DeviceAppCatalog;
use Modules\DeviceSubscriptions\Application\Services\DevicePlanCatalog;

/**
 * GET getPlans — the static plan catalog (ADR 0010). Returns the exact legacy
 * payload from config so the shipped app renders it unchanged.
 *
 * This is the one device endpoint that carries no `app_name`, so on the shared
 * `/api/*` surface it cannot tell the apps apart and serves the common catalog.
 * Under `/api/{app}/getPlans` the URL supplies the app instead (Phase D) — which is
 * why per-app pricing rides the base URL rather than a new request field: the base
 * URL is remote-config'd and needs no store release, a new field would.
 */
final class PlanController
{
    public function __construct(
        private readonly DevicePlanCatalog $plans,
        private readonly DeviceAppCatalog $apps,
    ) {}

    public function index(?string $app = null): JsonResponse
    {
        $appName = $app === null ? null : $this->apps->appForSlug($app);

        return response()->json($this->plans->payload($appName));
    }
}
