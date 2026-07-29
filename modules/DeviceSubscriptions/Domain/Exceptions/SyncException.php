<?php

namespace Modules\DeviceSubscriptions\Domain\Exceptions;

use RuntimeException;

/**
 * A domain-level sync failure carrying a stable machine code and HTTP status
 * (ADR 0011). Controllers catch this and render it through the platform error
 * envelope, so the wire codes are part of the contract the app depends on.
 */
final class SyncException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** The presented join token is unknown, already used, or past its TTL. */
    public static function invalidJoinToken(): self
    {
        return new self('INVALID_JOIN_TOKEN', 'This enrollment code is invalid or has expired.', 422);
    }

    /** The business is already at its seat allowance (Decision 3). */
    public static function allowanceExceeded(): self
    {
        return new self('ALLOWANCE_EXCEEDED', 'This subscription has no free device slots.', 409);
    }

    /**
     * Owner onboarding was attempted by a device with no verified subscription —
     * a business can only be stood up on top of a live licence.
     */
    public static function subscriptionRequired(): self
    {
        return new self('SUBSCRIPTION_REQUIRED', 'This device has no active subscription to enable sync.', 403);
    }

    /**
     * The joining device reported the legacy shared fallback device id, which
     * cannot be a stable per-device identity (Decision 5).
     */
    public static function fallbackDeviceRejected(): self
    {
        return new self('FALLBACK_DEVICE_REJECTED', 'This device cannot join sync (no stable device id).', 422);
    }

    /** The owner seat is the anchor of the business and is never revocable (R1). */
    public static function ownerSeatNonRevocable(): self
    {
        return new self('OWNER_SEAT_NON_REVOCABLE', 'The owner device cannot be removed.', 422);
    }

    /**
     * The pull cursor is older than the retained window (§9). The device must
     * re-bootstrap from a fresh snapshot rather than pull.
     */
    public static function cursorTooOld(): self
    {
        return new self('CURSOR_TOO_OLD', 'This device is too far behind and must re-sync from a snapshot.', 409);
    }
}
