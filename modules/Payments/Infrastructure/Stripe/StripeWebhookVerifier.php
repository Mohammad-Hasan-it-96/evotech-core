<?php

namespace Modules\Payments\Infrastructure\Stripe;

use Modules\Payments\Domain\Exceptions\WebhookSignatureException;

/**
 * Verifies the `Stripe-Signature` header on inbound webhooks natively — the same
 * scheme `stripe-php` implements, without the dependency (ADR 0009):
 *
 *   signed_payload = "{timestamp}.{raw_body}"
 *   expected       = HMAC-SHA256(signed_payload, webhook_secret)
 *
 * Enforces a timestamp tolerance (replay protection) and compares in constant
 * time. Returns the decoded event only when verification passes.
 */
final class StripeWebhookVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $tolerance = 300,
    ) {}

    /**
     * @return array<array-key, mixed> the verified, decoded event
     *
     * @throws WebhookSignatureException
     */
    public function verify(string $payload, ?string $signatureHeader, int $now): array
    {
        if ($this->secret === '') {
            throw new WebhookSignatureException('Stripe webhook secret is not configured.');
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookSignatureException('Missing Stripe-Signature header.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $timestamp === 0 || $signatures === []) {
            throw new WebhookSignatureException('Malformed Stripe-Signature header.');
        }

        if (abs($now - $timestamp) > $this->tolerance) {
            throw new WebhookSignatureException('Stripe-Signature timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }

        if (! $matched) {
            throw new WebhookSignatureException('Stripe-Signature does not match the payload.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new WebhookSignatureException('Webhook payload is not valid JSON.');
        }

        return $event;
    }
}
