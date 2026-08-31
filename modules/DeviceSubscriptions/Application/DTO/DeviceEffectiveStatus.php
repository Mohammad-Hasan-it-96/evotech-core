<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

use Illuminate\Support\Carbon;

/**
 * The subscription state a device's licence endpoints should report (ADR 0011,
 * Decision 2). It is read from the device's OWN row when it is unlinked (every
 * Fawateer 1.0.1 install), and from its BUSINESS when linked — a member device
 * that joined an existing shop may never have held its own trial, so the owner's
 * subscription is what covers it. The JSON shape check_device/create_device
 * return is identical either way; only the source differs.
 */
final readonly class DeviceEffectiveStatus
{
    public function __construct(
        public bool $isActive,
        public bool $isOnTrial,
        public ?string $planId,
        public ?Carbon $expiresAt,
    ) {}
}
