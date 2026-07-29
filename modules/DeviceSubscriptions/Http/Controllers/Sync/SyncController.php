<?php

namespace Modules\DeviceSubscriptions\Http\Controllers\Sync;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Responses\ApiResponse;
use Modules\DeviceSubscriptions\Domain\Contracts\SyncContext;
use Modules\DeviceSubscriptions\Domain\Exceptions\SyncException;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;

/**
 * Shared base for the multi-device-sync endpoints (ADR 0011). Centralises the two
 * things every sync action needs: reading the caller's identity from the
 * {@see SyncContext} (never from the request body), and translating a domain
 * {@see SyncException} into the platform error envelope with its stable wire code.
 *
 * The accessors abort with 401 if called with no authenticated seat — a
 * belt-and-braces guard, since the routes already sit behind `auth:device-sync`.
 */
abstract class SyncController
{
    public function __construct(protected readonly SyncContext $context) {}

    /** The authenticated seat's business id — the scope for every sync action. */
    protected function businessId(): int
    {
        $id = $this->context->businessId();

        if ($id === null) {
            abort(401);
        }

        return $id;
    }

    protected function seatId(): int
    {
        $id = $this->context->seatId();

        if ($id === null) {
            abort(401);
        }

        return $id;
    }

    protected function nodeId(): string
    {
        $id = $this->context->nodeId();

        if ($id === null) {
            abort(401);
        }

        return $id;
    }

    protected function appName(): string
    {
        $name = $this->context->appName();

        if ($name === null) {
            abort(401);
        }

        return $name;
    }

    protected function business(): DeviceBusiness
    {
        return DeviceBusiness::query()->findOrFail($this->businessId());
    }

    /** Owner-only guard (Decision 5): a member action gets 403 FORBIDDEN. */
    protected function requireOwner(): void
    {
        if (! $this->context->isOwner()) {
            throw new AuthorizationException('Only the owner device may perform this action.');
        }
    }

    /**
     * Enforce that a record belongs to the caller's business, returning 404 (never
     * 403) on a mismatch — cross-business access must be indistinguishable from a
     * record that does not exist (the feature's paramount isolation property).
     */
    protected function assertOwnedByBusiness(int $businessId): void
    {
        abort_unless($businessId === $this->businessId(), 404);
    }

    /**
     * Run a use case, mapping a domain SyncException to the error envelope.
     *
     * @param  Closure(): JsonResponse  $run
     */
    protected function guardSync(Closure $run): JsonResponse
    {
        try {
            return $run();
        } catch (SyncException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), status: $e->status);
        }
    }
}
