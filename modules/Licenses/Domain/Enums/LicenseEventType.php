<?php

namespace Modules\Licenses\Domain\Enums;

/**
 * Event kinds written to the immutable `license_events` ledger (constitution §6):
 * issuance, admin lifecycle transitions, device/domain activation changes, and
 * signed offline-token issuance.
 */
enum LicenseEventType: string
{
    case Issued = 'issued';
    case Renewed = 'renewed';
    case Suspended = 'suspended';
    case Reactivated = 'reactivated';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case TokenIssued = 'token_issued';
}
