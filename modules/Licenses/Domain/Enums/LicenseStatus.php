<?php

namespace Modules\Licenses\Domain\Enums;

/**
 * Lifecycle state of a license. Only Active licenses entitle a product;
 * Suspended is a reversible pause, Revoked is terminal, Expired is time-based.
 */
enum LicenseStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /** Whether the license currently grants entitlement (before expiry check). */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
