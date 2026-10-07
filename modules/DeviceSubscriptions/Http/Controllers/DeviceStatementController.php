<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Modules\DeviceSubscriptions\Application\Services\DeviceStatementService;
use Modules\DeviceSubscriptions\Application\Services\DeviceSubscriptionService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Public read-only statement links (ADR 0013).
 *
 * Create and revoke are device endpoints in the namespaced legacy-shim style (no
 * auth header; the device identifies itself by app_name + device_id), returning
 * plain JSON. Show is the public read the website renders, under /api/v1.
 *
 * Every "not there" answer is the same 404 — unknown, expired, revoked, another
 * device's link, an app without the feature — so nothing can be probed.
 */
final class DeviceStatementController
{
    public function __construct(
        private readonly DeviceSubscriptionService $devices,
        private readonly DeviceStatementService $statements,
    ) {}

    /** POST /api/{app}/statements */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'app_name' => 'required|string|max:255',
            'device_id' => 'required|string|max:255',
            'statement' => 'required|array',
            'statement.shop_name' => 'nullable|string|max:60',
            'statement.customer_name' => 'required|string|max:100',
            'statement.currency' => 'required|string|max:30',
            'statement.balance' => 'required|numeric',
            'statement.total_due' => 'required|numeric|min:0',
            'statement.total_paid' => 'required|numeric|min:0',
            'statement.generated_at' => 'required|date',
            'statement.entries' => 'present|array|max:300',
            'statement.entries.*.date' => 'required|date_format:Y-m-d',
            'statement.entries.*.direction' => 'required|in:due,paid',
            'statement.entries.*.amount' => 'required|numeric|min:0',
            'statement.entries.*.note' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $device = $this->device($request);

        if ($device === null) {
            return $this->notFound();
        }

        /** @var array<string, mixed> $statement */
        $statement = $validator->validated()['statement'];
        $link = $this->statements->create($device, $statement);

        return response()->json([
            'success' => true,
            'token' => $link['token'],
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toISOString(),
        ], 201);
    }

    /** DELETE /api/{app}/statements/{token} */
    public function destroy(Request $request, string $app, string $token): JsonResponse|Response
    {
        $device = $this->device($request);

        if ($device === null || ! $this->statements->revoke($device, $token)) {
            return $this->notFound();
        }

        return response()->noContent();
    }

    /** GET /api/v1/statements/{token} — public, rendered by evotech-web. */
    public function show(string $token): JsonResponse
    {
        $statement = $this->statements->find($token);

        if ($statement === null) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                ...$statement->payload,
                'expires_at' => $statement->expires_at->toISOString(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /** The registered, real (non-fallback) device of an app that offers links. */
    private function device(Request $request): ?DeviceSubscription
    {
        $appName = $request->input('app_name');
        $deviceId = $request->input('device_id');

        if (! is_string($appName) || ! is_string($deviceId) || ! $this->statements->enabled($appName)) {
            return null;
        }

        $device = $this->devices->find($deviceId, $appName);

        return $device === null || $device->isFallback() ? null : $device;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not found'], 404);
    }
}
