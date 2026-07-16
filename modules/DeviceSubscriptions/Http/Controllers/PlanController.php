<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DeviceSubscriptions\Application\Services\DevicePlanCatalog;

/**
 * GET getPlans — the static plan catalog (ADR 0010). Returns the exact legacy
 * payload from config so the shipped app renders it unchanged.
 */
final class PlanController
{
    public function __construct(private readonly DevicePlanCatalog $plans) {}

    public function index(): JsonResponse
    {
        return response()->json($this->plans->payload());
    }
}
