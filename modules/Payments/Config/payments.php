<?php

return [
    /*
     * The active payment gateway (ADR 0006 / ADR 0009). `manual` records
     * offline/reconciled receipts (bank transfer, cash); `stripe` collects card
     * payments through Stripe's PaymentIntent + webhook flow. Defaults to `manual`
     * so no external credentials are required in local/CI.
     */
    'gateway' => env('PAYMENTS_GATEWAY', 'manual'),

    /*
     * Stripe live adapter settings (ADR 0009). All secret material comes from the
     * environment — never commit real keys. The adapter talks to Stripe's REST API
     * directly (no SDK dependency); `api_base` is overridable for testing.
     */
    'stripe' => [
        // Secret API key (server-side). STRIPE_SECRET=sk_live_… / sk_test_…
        'secret' => env('STRIPE_SECRET'),

        // Publishable key (returned to the browser to confirm the PaymentIntent).
        'publishable' => env('STRIPE_KEY'),

        // Webhook signing secret used to verify inbound events. STRIPE_WEBHOOK_SECRET=whsec_…
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Max age (seconds) of a webhook timestamp before it is rejected as a replay.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),

        // Stripe API base URL. Overridden in tests; never change in production.
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
    ],
];
