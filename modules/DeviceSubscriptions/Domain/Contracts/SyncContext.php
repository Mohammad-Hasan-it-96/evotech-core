<?php

namespace Modules\DeviceSubscriptions\Domain\Contracts;

use Modules\Gateway\Domain\Contracts\ProductContext;

/**
 * The authenticated device seat's identity for the current request (ADR 0011).
 *
 * Resolved from the `device-sync` guard, mirroring Gateway's {@see ProductContext}
 * so callers never touch the seat's Eloquent model directly. This is where
 * cross-business isolation is enforced: the `businessId()` is derived from the
 * authenticated seat server-side, and a request body's business/app_name is never
 * trusted for authorization (Decision 4 — the feature's paramount security property).
 *
 * All accessors return null when no seat is authenticated.
 */
interface SyncContext
{
    /** True when a device seat is authenticated on the current request. */
    public function isAuthenticated(): bool;

    /** The authenticated seat's business id — the scoping key for all sync data. */
    public function businessId(): ?int;

    /** The authenticated seat's own id. */
    public function seatId(): ?int;

    /** The HLC node id of the authenticated device (§3), for the no-echo pull filter. */
    public function nodeId(): ?string;

    /** The authenticated device's raw device id. */
    public function deviceId(): ?string;

    /** The app the authenticated seat belongs to. */
    public function appName(): ?string;

    /** True when the authenticated seat is the business owner (Decision 5). */
    public function isOwner(): bool;
}
