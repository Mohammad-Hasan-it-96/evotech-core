<?php

namespace Modules\Payments\Domain\Contracts;

use Modules\Payments\Domain\Enums\PaymentMethod;

/**
 * The outcome of a {@see PaymentGateway} collection attempt. The service persists
 * the Payment from a successful result.
 */
final readonly class GatewayPaymentResult
{
    public function __construct(
        public bool $succeeded,
        public PaymentMethod $method,
        public ?string $reference,
        public string $amount,
        public string $currency,
    ) {}
}
