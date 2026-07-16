<?php

namespace Modules\DeviceSubscriptions\Domain\Enums;

/**
 * The subscription plans the shipped app knows about. The `value` is the exact
 * `plan_id` string stored on a device and sent by the app; durations match the
 * legacy activation math (unknown plan → 0 months, i.e. immediate expiry).
 */
enum DevicePlan: string
{
    case HalfYear = 'half_year';
    case Yearly = 'yearly';

    public function durationMonths(): int
    {
        return match ($this) {
            self::HalfYear => 6,
            self::Yearly => 12,
        };
    }

    /** Duration for an arbitrary plan_id string; 0 for anything unrecognized. */
    public static function monthsFor(?string $planId): int
    {
        return self::tryFrom((string) $planId)?->durationMonths() ?? 0;
    }
}
