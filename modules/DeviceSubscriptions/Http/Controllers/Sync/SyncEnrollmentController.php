<?php

namespace Modules\DeviceSubscriptions\Http\Controllers\Sync;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Modules\Core\Http\Responses\ApiResponse;
use Modules\DeviceSubscriptions\Application\Services\SyncEnrollmentService;
use Modules\DeviceSubscriptions\Domain\Contracts\SyncContext;
use Modules\DeviceSubscriptions\Domain\Models\DeviceJoinToken;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;

/**
 * Enrollment and seat management for multi-device sync (ADR 0011, Decisions 3, 5, 13).
 *
 * `enroll` is public — a joining device has no seat yet and presents its join
 * token in the body. Everything else runs behind `auth:device-sync`, and the
 * owner-only actions (minting tokens, attaching a bootstrap, revoking a device)
 * additionally require the owner seat. Every {joinToken}/{seat} is re-scoped to
 * the caller's business, so a valid id from another business 404s.
 */
final class SyncEnrollmentController extends SyncController
{
    public function __construct(SyncContext $context, private readonly SyncEnrollmentService $enrollment)
    {
        parent::__construct($context);
    }

    /**
     * POST /api/v1/sync/business — owner onboarding (public, licensing identity).
     *
     * Stands up (or recovers) the caller device's sync business and returns its
     * owner seat token. This is the entry point for the whole feature: it is what
     * a licensed single device calls to enable multi-device, before any join token
     * or seat exists. Authenticated by the device's licensing identity, not a sync
     * seat (it has none yet).
     */
    public function establish(Request $request): JsonResponse
    {
        $request->validate([
            'app_name' => ['required', 'string', 'max:50'],
            'device_id' => ['required', 'string', 'max:200'],
            'push_token' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->guardSync(function () use ($request): JsonResponse {
            $enrolled = $this->enrollment->onboardOwner(
                (string) $request->string('app_name'),
                (string) $request->string('device_id'),
                $request->filled('push_token') ? (string) $request->string('push_token') : null,
            );

            $enrolled->seat->loadMissing('business');

            return ApiResponse::success([
                'sync_token' => $enrolled->plaintext,
                'seat' => [
                    'uuid' => $enrolled->seat->uuid,
                    'role' => $enrolled->seat->role,
                    'device_id' => $enrolled->seat->device_id,
                ],
                'business_uuid' => $enrolled->seat->business->uuid,
                'device_allowance' => $enrolled->seat->business->device_allowance,
                'bootstrap' => [
                    'cursor' => $enrolled->bootstrap->cursor,
                    'snapshot_url' => $enrolled->bootstrap->snapshotUrl,
                    'snapshot_sha256' => $enrolled->bootstrap->snapshotSha256,
                ],
            ], status: 201);
        });
    }

    /** POST /api/v1/sync/join-tokens — owner mints a single-use enrollment QR. */
    public function mintJoinToken(): JsonResponse
    {
        $this->requireOwner();

        return $this->guardSync(function (): JsonResponse {
            $business = $this->business();
            $minted = $this->enrollment->mintJoinToken($business);

            return ApiResponse::success([
                'join_token' => $minted->plaintext,
                'expires_at' => $minted->token->expires_at->toIso8601String(),
                'business_uuid' => $business->uuid,
            ], status: 201);
        });
    }

    /**
     * POST /api/v1/sync/join-tokens/{joinToken}/bootstrap — owner uploads the
     * bootstrap snapshot and its cursor `C` (Decision 13). The cursor is the
     * owner's OWN local pull cursor; the SHA-256 is owner-computed and stored as-is
     * so the joiner's integrity check is end-to-end owner→joiner (H2).
     */
    public function attachBootstrap(Request $request, DeviceJoinToken $joinToken): JsonResponse
    {
        $this->requireOwner();
        $this->assertOwnedByBusiness($joinToken->device_business_id);

        $maxKb = Config::integer('device-subscriptions.sync.snapshot_max_kb');

        $request->validate([
            'cursor' => ['required', 'integer', 'min:0'],
            'snapshot_sha256' => ['required', 'string', 'size:64'],
            'snapshot' => ['required', 'file', 'max:'.$maxKb],
        ]);

        $file = $request->file('snapshot');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $path = $file->store('snapshots', Config::string('device-subscriptions.sync.snapshot_disk'));

        if ($path === false) {
            abort(500);
        }

        $cursor = $request->integer('cursor');
        $this->enrollment->attachBootstrap($joinToken, $cursor, $path, (string) $request->string('snapshot_sha256'));

        return ApiResponse::success([
            'join_token_uuid' => $joinToken->uuid,
            'cursor' => $cursor,
        ]);
    }

    /** POST /api/v1/sync/enroll — a joining device redeems a join token (public). */
    public function enroll(Request $request): JsonResponse
    {
        $request->validate([
            'join_token' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:200'],
            'push_token' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->guardSync(function () use ($request): JsonResponse {
            $enrolled = $this->enrollment->enroll(
                (string) $request->string('join_token'),
                (string) $request->string('device_id'),
                $request->filled('push_token') ? (string) $request->string('push_token') : null,
            );

            $enrolled->seat->loadMissing('business');

            return ApiResponse::success([
                'sync_token' => $enrolled->plaintext,
                'seat' => [
                    'uuid' => $enrolled->seat->uuid,
                    'role' => $enrolled->seat->role,
                    'device_id' => $enrolled->seat->device_id,
                ],
                'business_uuid' => $enrolled->seat->business->uuid,
                'bootstrap' => [
                    'cursor' => $enrolled->bootstrap->cursor,
                    'snapshot_url' => $enrolled->bootstrap->snapshotUrl,
                    'snapshot_sha256' => $enrolled->bootstrap->snapshotSha256,
                ],
            ], status: 201);
        });
    }

    /** GET /api/v1/sync/devices — the business's seats (any authenticated device). */
    public function devices(): JsonResponse
    {
        $seats = $this->enrollment->seatsFor($this->business());

        return ApiResponse::success(
            $seats->map(fn (DeviceSeat $seat): array => [
                'uuid' => $seat->uuid,
                'device_id' => $seat->device_id,
                'role' => $seat->role,
                'revoked' => ! $seat->isActive(),
                'last_used_at' => $seat->last_used_at?->toIso8601String(),
                'created_at' => $seat->created_at?->toIso8601String(),
            ])->all(),
        );
    }

    /**
     * DELETE /api/v1/sync/devices/{seat} — owner revokes a member seat. The owner
     * seat is refused (R1); revocation is not a remote wipe (R2).
     */
    public function revokeDevice(DeviceSeat $seat): JsonResponse
    {
        $this->requireOwner();
        $this->assertOwnedByBusiness($seat->device_business_id);

        return $this->guardSync(function () use ($seat): JsonResponse {
            $this->enrollment->revokeSeat($seat);

            return ApiResponse::success([
                'uuid' => $seat->uuid,
                'revoked' => true,
            ]);
        });
    }
}
