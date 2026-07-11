<?php

namespace Modules\Payments\Application\DTO;

/**
 * A Stripe PaymentIntent as this platform needs it: the id we correlate webhooks
 * against, the client secret the browser uses to confirm the card, and Stripe's
 * current status. (ADR 0009)
 */
final readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        public string $clientSecret,
        public string $status,
    ) {}
}
