<?php

namespace Modules\Payments\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when an inbound Stripe webhook fails signature/timestamp verification
 * (ADR 0009). The controller maps this to a 400 so Stripe treats delivery as
 * failed; a settlement is never recorded off an unverified event.
 */
final class WebhookSignatureException extends RuntimeException {}
