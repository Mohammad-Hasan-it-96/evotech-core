<?php

/*
 * DeviceSubscriptions module configuration (ADR 0010).
 *
 * Plans and currency preserve the exact `getPlans` response the shipped SmartAgent
 * app expects — they moved here verbatim from the legacy controller so pricing can
 * change without a code deploy. Keep the response shape stable while the shim lives.
 */
return [
    /*
     * Push driver. `null` is a safe no-op used locally and in CI (no Firebase
     * dependency to run the suite). Set DEVICE_PUSH_NOTIFIER=firebase where FCM
     * credentials are configured.
     */
    'push_notifier' => env('DEVICE_PUSH_NOTIFIER', 'null'),

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        // Absolute path to (or contents of) the FCM service-account JSON.
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    /*
     * Legacy data migration source (device-subscriptions:import-legacy). Points at
     * the old app_harfoshs table on a separate DB connection. Leave the connection
     * unset until you actually run the import.
     */
    'legacy' => [
        'connection' => env('DEVICE_LEGACY_CONNECTION'),
        'table' => env('DEVICE_LEGACY_TABLE', 'app_harfoshs'),
    ],

    /*
     * App download metadata surfaced by GET app-download. Placeholder until the
     * Download Center (ADR 0008) backs this; the human-facing page lives in
     * evotech-web.
     */
    'download' => [
        'latest_version' => env('DEVICE_APP_LATEST_VERSION'),
        'links' => [
            // 'arm64-v8a'   => env('DEVICE_APP_APK_ARM64'),
            // 'armeabi-v7a' => env('DEVICE_APP_APK_ARM32'),
        ],
    ],

    /*
     * Per-app settings, keyed by the `app_name` the client sends (exactly as the
     * shipped apps send it: 'Fawateer', 'SmartAgent'). Lookup is case-insensitive.
     *
     * `trial_days` — length of the server-granted free trial, stamped once on first
     * registration. **An app absent from this map, or set to 0, gets NO trial.**
     * SmartAgent deliberately has none: its owner never asked for one, and granting
     * it here would silently change that product's monetization.
     *
     * `label` — the product name used in push copy, so a Fawateer user is not asked
     * to renew "المندوب الذكي".
     */
    'apps' => [
        'Fawateer' => [
            'label' => 'فواتير',
            'trial_days' => 30,
        ],
        'SmartAgent' => [
            'label' => 'المندوب الذكي',
            'trial_days' => 0,
        ],
    ],

    'currency' => [
        'code' => 'USD',
        'symbol' => '$',
    ],

    /*
     * `price_after_discount` is read by the shipped Fawateer plan parser; null means
     * "no discount". Both apps read one catalog today — getPlans carries no app_name,
     * so it cannot vary per app. Per-app pricing is Phase D of
     * docs/ROADMAP-APP-APIS.md (namespace the shim by base URL, which the apps'
     * separate remote-config files already allow).
     */
    'plans' => [
        [
            'id' => 'half_year',
            'title' => 'الخطة نصف السنوية',
            'duration_months' => 6,
            'price' => 12,
            'price_after_discount' => null,
            'enabled' => true,
            'recommended' => false,
            'description' => 'أفضل خيار للتجربة طويلة المدى',
        ],
        [
            'id' => 'yearly',
            'title' => 'الخطة السنوية',
            'duration_months' => 12,
            'price' => 20,
            'price_after_discount' => null,
            'enabled' => true,
            'recommended' => true,
            'description' => 'الأكثر توفيراً',
        ],
    ],
];
