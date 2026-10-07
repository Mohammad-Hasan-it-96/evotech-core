<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\DeviceSubscriptions\Application\Services\DeviceStatementService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceStatement;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Http\Resources\DeviceStatementResource;

/**
 * Staff console for shared statement links (ADR 0013), behind auth:sanctum.
 *
 * Support uses it to answer "is my link still up?" and to take a link down when the
 * customer it describes asks — the shop can stop it from the app too, but the
 * debtor has no app.
 */
final class DeviceStatementAdminController
{
    public function __construct(private readonly DeviceStatementService $statements) {}

    /** GET /api/v1/device-subscriptions/{deviceSubscription}/statements — live links, newest first. */
    public function index(DeviceSubscription $deviceSubscription): AnonymousResourceCollection
    {
        return DeviceStatementResource::collection(
            $deviceSubscription->statements()->live()->latest()->get(),
        );
    }

    /** DELETE /api/v1/device-statements/{deviceStatement} — stop a link now. */
    public function destroy(DeviceStatement $deviceStatement): JsonResponse
    {
        $this->statements->deleteByStaff($deviceStatement);

        return response()->json(status: 204);
    }
}
