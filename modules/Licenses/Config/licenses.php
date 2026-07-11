<?php

return [
    /*
     * Prefix stamped on every generated license key, e.g. "EVO" -> EVO-XXXX-...
     */
    'key_prefix' => env('LICENSE_KEY_PREFIX', 'EVO'),

    /*
     * How many device/domain activations a newly issued license allows by
     * default. Can be overridden per license at issuance time.
     */
    'default_max_activations' => (int) env('LICENSE_DEFAULT_MAX_ACTIVATIONS', 1),

    /*
     * Signed offline license tokens (ADR 0005) — EdDSA/Ed25519 JWS a device
     * verifies without connectivity. Keys are managed secrets (§6.10): base64 of
     * the raw Ed25519 secret (64 bytes) and public (32 bytes) keys. Generate with
     * `php artisan licenses:keygen`. Never commit real keys.
     */
    'offline_tokens' => [
        'issuer' => env('LICENSE_TOKEN_ISSUER', 'evotech-platform'),
        'ttl_days' => (int) env('LICENSE_TOKEN_TTL_DAYS', 14),
        'key_id' => env('LICENSE_TOKEN_KEY_ID', 'evo-license-key-1'),
        'private_key' => env('LICENSE_TOKEN_PRIVATE_KEY'),
        'public_key' => env('LICENSE_TOKEN_PUBLIC_KEY'),
    ],
];
