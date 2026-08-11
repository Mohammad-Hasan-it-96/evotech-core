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
     *
     * `slug` — the app's URL namespace, `/api/{slug}/*` (Phase D of
     * docs/ROADMAP-APP-APIS.md). `getPlans` carries no app_name, so a shared base URL
     * cannot serve different plans per app. Each app reads its own remote-config
     * file, so pointing its baseUrl at `…/api/{slug}` namespaces every call it makes
     * and needs **no store release**. The un-namespaced `/api/*` stays live for the
     * builds still pointed at it and serves the shared catalog below.
     *
     * `plans` — optional per-app catalog. **Omit it and the app gets the shared
     * `plans` list below** — which is what both apps do today, so nothing changes
     * until a price is deliberately set here.
     *
     * `firebase` — the app's OWN Firebase project. These are genuinely separate
     * projects, not two apps in one, so a credential is not interchangeable: a
     * service account for smart-agent-5b153 cannot reach a Fawateer device
     * because that token does not exist in that project (FCM answers 404
     * UNREGISTERED). Hence per-app rather than one global credential.
     *
     * `credentials` is an absolute path to the service-account JSON — keep it
     * OUTSIDE the repo (it holds a private key) and never commit it. Leave the
     * env unset and pushes for that app no-op with a warning.
     */
    'apps' => [
        'Fawateer' => [
            'label' => 'فواتير',
            'trial_days' => 30,
            'slug' => 'fawateer',
            // 'plans' => [...],  // ← per-app catalog; omitted = the shared list below.
            'firebase' => [
                'project_id' => env('FIREBASE_PROJECT_ID_FAWATEER', 'fawateer-4c9bc'),
                'credentials' => env('FIREBASE_CREDENTIALS_FAWATEER'),
            ],
        ],
        'SmartAgent' => [
            'label' => 'المندوب الذكي',
            'trial_days' => 0,
            'slug' => 'smartagent',
            'firebase' => [
                'project_id' => env('FIREBASE_PROJECT_ID_SMARTAGENT', 'smart-agent-5b153'),
                'credentials' => env('FIREBASE_CREDENTIALS_SMARTAGENT'),
            ],
        ],
    ],

    /*
     * Multi-device sync (ADR 0011). One subscription = one business owning N
     * devices. All additive: absent config leaves the legacy single-device shim
     * untouched.
     *
     * `join_token_ttl_minutes` — how long an owner-minted enrollment QR stays
     *   redeemable. Short by design; it is a single-use binding, not a credential.
     * `snapshot_url_ttl_minutes` — lifetime of the signed URL a joining device
     *   uses to fetch the bootstrap snapshot (ADR 0008 delivery).
     * `snapshot_disk` — the PRIVATE disk bootstrap snapshots are staged on. Never
     *   public; served only through the signed delivery route and deleted once
     *   consumed (Decision 13 — snapshots are never retained).
     * `pull_limit` / `pull_max_limit` — default and hard-capped page size for a
     *   pull, so one poll cannot ask for an unbounded window.
     *
     * Device tiers (the 1/3/5 seat allowances) now live on the plan itself
     * (`device_plans.device_allowance`), set in the dashboard — see Decision 3 and
     * DevicePlan::allowanceFor(). The keys below are only the default and a legacy
     * override consulted when a plan row cannot be resolved.
     */
    'sync' => [
        'join_token_ttl_minutes' => (int) env('DEVICE_SYNC_JOIN_TTL_MINUTES', 15),
        'snapshot_url_ttl_minutes' => (int) env('DEVICE_SYNC_SNAPSHOT_TTL_MINUTES', 15),
        'snapshot_disk' => env('DEVICE_SYNC_SNAPSHOT_DISK', 'device-sync'),
        'snapshot_max_kb' => (int) env('DEVICE_SYNC_SNAPSHOT_MAX_KB', 51200),
        'pull_limit' => (int) env('DEVICE_SYNC_PULL_LIMIT', 200),
        'pull_max_limit' => (int) env('DEVICE_SYNC_PULL_MAX_LIMIT', 500),
        /*
         * Change-log retention window in days (ADR 0011, Decision 14). The daily
         * prune removes changes older than this; a device offline longer than the
         * window cannot catch up incrementally and re-bootstraps via a snapshot.
         * 0 disables pruning (keep the whole log). Wide by default — a phone away
         * for two months is a re-provision, not a routine sync.
         */
        'retention_days' => (int) env('DEVICE_SYNC_RETENTION_DAYS', 60),
        'default_allowance' => (int) env('DEVICE_SYNC_DEFAULT_ALLOWANCE', 1),
        /*
         * Legacy override, plan_id → device allowance. The plan's own
         * `device_allowance` column is the source of truth now (set in the
         * dashboard); this map is consulted only when a plan row cannot be resolved,
         * and is kept so a value set before tiers moved onto the plan is never
         * silently dropped. Prefer editing the plan. Example:
         *   'yearly' => 3,  // the annual plan includes up to 3 devices
         */
        'plan_allowance' => [
            // 'yearly' => 3,
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
