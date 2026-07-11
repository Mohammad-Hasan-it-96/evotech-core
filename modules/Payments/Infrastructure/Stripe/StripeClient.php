<?php

namespace Modules\Payments\Infrastructure\Stripe;

use Illuminate\Support\Facades\Http;

/**
 * A thin, dependency-free Stripe REST client (ADR 0009). Only the calls this
 * platform needs are implemented, over Laravel's HTTP client (`Http` facade) —
 * no `stripe-php` dependency. Secrets are injected from `config('payments.stripe')`.
 */
final class StripeClient
{
    public function __construct(
        private readonly string $secret,
        private readonly string $apiBase,
    ) {}

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    /**
     * Create a PaymentIntent for the given minor-unit amount.
     *
     * @param  array<string, string>  $metadata  correlation data echoed back on webhooks
     * @return array<array-key, mixed> the decoded Stripe PaymentIntent
     */
    public function createPaymentIntent(int $amountMinor, string $currency, array $metadata): array
    {
        $response = Http::withToken($this->secret)
            ->asForm()
            ->acceptJson()
            ->post($this->apiBase.'/v1/payment_intents', [
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'metadata' => $metadata,
                'automatic_payment_methods' => ['enabled' => 'true'],
            ]);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
