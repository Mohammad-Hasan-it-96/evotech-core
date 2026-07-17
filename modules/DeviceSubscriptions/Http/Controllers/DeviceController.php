<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\DeviceSubscriptions\Application\Services\DeviceSubscriptionService;

/**
 * Device self-service endpoints (ADR 0010). Reachable two ways for the same logic:
 *  - the legacy compatibility shim (unversioned /api/*, public) for the shipped app;
 *  - the versioned twins (/api/v1/device/*, auth:product) for future app releases.
 *
 * Responses intentionally return the EXACT legacy JSON shapes (not the platform
 * envelope) so repointing the shipped app is byte-compatible. Validation is done
 * inline (not via FormRequest) to keep the legacy error body, since the global
 * exception renderer would otherwise reformat it.
 */
final class DeviceController
{
    public function __construct(private readonly DeviceSubscriptionService $devices) {}

    /**
     * POST create_device — register a device, refresh its token, or file a plan
     * request. The app reuses this one endpoint for all three.
     */
    public function createDevice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_name' => 'required|string',
            'device_id' => 'required|string',
            'full_name' => 'required|string',
            'phone' => 'required|string',
            'fcm_token' => 'nullable|string',
            // Purchase intent (Fawateer): kept permissive on purpose — a 422 here
            // would fail registration outright in the shipped app.
            'requested_plan' => 'nullable|string|max:50',
            'contact_method' => 'nullable|string|max:30',
            'status' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $device = $this->devices->registerOrTouch(
            appName: (string) $request->string('app_name'),
            deviceId: (string) $request->string('device_id'),
            fullName: (string) $request->string('full_name'),
            phone: (string) $request->string('phone'),
            fcmToken: $this->optional($request, 'fcm_token'),
            requestedPlan: $this->optional($request, 'requested_plan'),
            contactMethod: $this->optional($request, 'contact_method'),
            status: $this->optional($request, 'status'),
        );

        return response()->json([
            // isActive(), not the raw column: legacy only forced is_verified to 0
            // past expiry on check_device, so create_device could answer
            // "verified" alongside an expires_at in the past. Harmless while every
            // device was operator-activated; with trials it means a device whose
            // trial has lapsed re-registers and is told it is verified. Both
            // endpoints now answer with one definition.
            'is_verified' => (int) $device->isActive(),
            'is_trial' => (int) $device->isOnTrial(),
            'expires_at' => $device->expires_at,
            'plan' => $device->plan_id,
            'fcm_token' => $device->fcm_token,
            'server_time' => Carbon::now()->toISOString(),
        ]);
    }

    /** POST check_device — current subscription status (is_verified forced 0 past expiry). */
    public function checkDevice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
            'app_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $device = $this->devices->find(
            (string) $request->string('device_id'),
            (string) $request->string('app_name'),
        );

        if ($device === null) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'is_verified' => (int) $device->isActive(),
            'is_trial' => (int) $device->isOnTrial(),
            'plan' => $device->plan_id,
            'expires_at' => $device->expires_at,
            'server_time' => Carbon::now()->toISOString(),
        ]);
    }

    /**
     * POST update_my_data — partial profile update.
     *
     * Every field beyond the device key is optional: the app rotates its push
     * token by sending fcm_token alone, and edits the profile by sending
     * full_name/phone alone. Requiring all of them 422'd token rotation, which
     * silently cost the device its live-unlock push.
     */
    public function updateMyData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
            'app_name' => 'required|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:255',
            'fcm_token' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $device = $this->devices->find(
            (string) $request->string('device_id'),
            (string) $request->string('app_name'),
        );

        if ($device === null) {
            return $this->notFound();
        }

        $this->devices->updateProfile(
            $device,
            $this->optional($request, 'full_name'),
            $this->optional($request, 'phone'),
            $this->optional($request, 'fcm_token'),
        );

        return response()->json(['success' => true]);
    }

    /** POST add_review — store a rating/comment on the device. */
    public function addReview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
            'app_name' => 'required|string|max:255',
            'stars' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $device = $this->devices->find(
            (string) $request->string('device_id'),
            (string) $request->string('app_name'),
        );

        if ($device === null) {
            return $this->notFound();
        }

        $this->devices->addReview(
            $device,
            $request->integer('stars'),
            $request->filled('comment') ? (string) $request->string('comment') : null,
        );

        return response()->json(['success' => true]);
    }

    /** A supplied non-empty field, or null when the app omitted it. */
    private function optional(Request $request, string $key): ?string
    {
        return $request->filled($key) ? (string) $request->string($key) : null;
    }

    private function validationError(MessageBag $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $errors->toArray(),
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Device not found',
        ], 404);
    }
}
