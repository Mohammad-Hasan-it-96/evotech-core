<?php

namespace Modules\Payments\Infrastructure\Stripe;

use Illuminate\Support\Arr;

/**
 * A read-only, type-safe view over a decoded Stripe JSON object (a PaymentIntent
 * response or a webhook event). Stripe payloads are untyped `mixed`; these
 * accessors narrow each field so callers never cast `mixed` (ADR 0009).
 */
final class StripePayload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    public function string(string $key, string $default = ''): string
    {
        $value = Arr::get($this->data, $key);

        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = Arr::get($this->data, $key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : $default;
    }
}
