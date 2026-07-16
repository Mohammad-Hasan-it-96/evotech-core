<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Http\Controllers\ApiController;
use Modules\DeviceSubscriptions\Application\Services\DeviceSubscriptionService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Http\Resources\DeviceSubscriptionResource;

/**
 * Admin/staff endpoints (auth:sanctum). In the legacy backend activateDevice and
 * getDevice were public — the two worst holes (anonymous activation, anonymous PII
 * dump). ADR 0010 moves them behind staff auth. Both the legacy-shaped shim methods
 * and the clean versioned methods live here.
 */
final class DeviceAdminController extends ApiController
{
    public function __construct(private readonly DeviceSubscriptionService $devices) {}

    // --- Legacy shim (auth:sanctum), legacy response shapes -------------------

    /** POST activateDevice — activate/extend by device_id. */
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'plan_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $device = $this->devices->activate(
            (string) $request->string('device_id'),
            (string) $request->string('plan_id'),
        );

        if ($device === null) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        return response()->json([
            'success' => true,
            'is_verified' => 1,
            'plan' => $device->plan_id,
            'expires_at' => $device->expires_at,
        ]);
    }

    /** GET getDevice — list all devices (legacy shape). */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->devices->all(),
        ]);
    }

    // --- Versioned staff API (auth:sanctum), platform envelope ----------------

    /** GET /api/v1/device-subscriptions — paginated, enveloped listing. */
    public function indexV1(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return DeviceSubscriptionResource::collection(
            DeviceSubscription::query()->latest()->paginate($perPage)
        );
    }

    /** POST /api/v1/device-subscriptions/{deviceSubscription}/activate. */
    public function activateV1(Request $request, DeviceSubscription $deviceSubscription): DeviceSubscriptionResource
    {
        $request->validate(['plan_id' => 'required|string']);

        $this->devices->activate(
            (string) $deviceSubscription->device_id,
            (string) $request->string('plan_id'),
        );

        return DeviceSubscriptionResource::make($deviceSubscription->refresh());
    }
}
