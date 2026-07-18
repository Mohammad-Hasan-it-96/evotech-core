<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Http\Controllers\ApiController;
use Modules\DeviceSubscriptions\Application\Services\DevicePlanCatalog;
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

    /**
     * POST activateDevice — activate/extend by device_id.
     *
     * `app_name` is optional and new: the legacy contract never had it, but this
     * deployment serves several products, so pass it whenever it is known and the
     * lookup is scoped to the right one. The shared fallback id is rejected as
     * not-found — see [DeviceSubscription::FALLBACK_DEVICE_ID].
     */
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'plan_id' => 'required|string',
            'app_name' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $device = $this->devices->findForActivation(
            (string) $request->string('device_id'),
            $request->filled('app_name') ? (string) $request->string('app_name') : null,
        );

        if ($device === null) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $device = $this->devices->activate($device, (string) $request->string('plan_id'));

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

    /**
     * GET /api/v1/device-subscriptions — paginated, enveloped listing.
     *
     * Backs the operator console: `status=pending` is the work queue (purchase
     * intents filed from the app), `app_name` separates the products sharing this
     * deployment, and `q` searches the columns an operator has to hand when a
     * customer contacts them over WhatsApp — their phone, name, or device id.
     */
    public function indexV1(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $devices = DeviceSubscription::query()
            ->when(
                $request->filled('status'),
                fn (Builder $query): Builder => $query->where('status', (string) $request->string('status')),
            )
            ->when(
                $request->filled('app_name'),
                fn (Builder $query): Builder => $query->where('app_name', (string) $request->string('app_name')),
            )
            ->when(
                $request->filled('q'),
                function (Builder $query) use ($request): Builder {
                    $term = '%'.(string) $request->string('q').'%';

                    return $query->where(fn (Builder $inner): Builder => $inner
                        ->where('device_id', 'like', $term)
                        ->orWhere('full_name', 'like', $term)
                        ->orWhere('phone', 'like', $term));
                },
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return DeviceSubscriptionResource::collection($devices);
    }

    /**
     * GET /api/v1/device-subscriptions/plans — the catalog the operator activates
     * against.
     *
     * The same config catalog the apps see, so the operator can only pick a plan
     * id the device will actually recognise. Staff-scoped rather than reusing the
     * legacy shim's public getPlans, which Phase E retires.
     *
     * `app_name` selects the app's own catalog (Phase D). The console must pass the
     * device's app: with per-app plans, showing the shared list would offer plan ids
     * the device's catalog may not define — and an unrecognised id activates a
     * 0-month term, expiring instantly for someone who has just paid.
     */
    public function plansV1(Request $request, DevicePlanCatalog $plans): JsonResponse
    {
        $payload = $plans->payload(
            $request->filled('app_name') ? (string) $request->string('app_name') : null,
        );

        return response()->json([
            'data' => [
                'currency' => $payload['currency'] ?? null,
                'plans' => $payload['plans'] ?? [],
            ],
        ]);
    }

    /**
     * POST /api/v1/device-subscriptions/{deviceSubscription}/activate.
     *
     * Activates the bound row itself. It used to pass that row's `device_id` back
     * to a service that re-queried by id alone — so where an id was not unique
     * (the shared fallback bucket) the operator could activate one device and
     * license another, then be shown the untouched row and told it failed.
     *
     * The fallback bucket is refused: it is not a device, and activating it would
     * license every device that landed in it.
     */
    public function activateV1(Request $request, DeviceSubscription $deviceSubscription): DeviceSubscriptionResource|JsonResponse
    {
        $request->validate(['plan_id' => 'required|string']);

        if ($deviceSubscription->isFallback()) {
            return response()->json([
                'message' => 'This row is the shared unreadable-id bucket, not a single device, and cannot be activated. Ask the customer to reopen the app so it registers under its real device id.',
            ], 422);
        }

        $device = $this->devices->activate($deviceSubscription, (string) $request->string('plan_id'));

        return DeviceSubscriptionResource::make($device);
    }

    /**
     * POST /api/v1/device-subscriptions/{deviceSubscription}/decline.
     *
     * The "no" the console never had. Without it the only way to clear a request
     * the operator would not fulfil was to activate it — selling a plan to close
     * a ticket — so junk requests accumulated and the pending queue stopped being
     * a work list.
     *
     * Refused unless a request is actually open: declining a row with no pending
     * intent would stamp `declined` on an ordinary device, which reads in the
     * console as "this customer was rejected" about someone who never asked for
     * anything. Idempotent re-declines are refused for the same reason.
     */
    public function declineV1(DeviceSubscription $deviceSubscription): DeviceSubscriptionResource|JsonResponse
    {
        if ($deviceSubscription->status !== DeviceSubscription::STATUS_PENDING) {
            return response()->json([
                'message' => 'This device has no pending request to decline.',
            ], 422);
        }

        $device = $this->devices->declineRequest($deviceSubscription);

        return DeviceSubscriptionResource::make($device);
    }
}
