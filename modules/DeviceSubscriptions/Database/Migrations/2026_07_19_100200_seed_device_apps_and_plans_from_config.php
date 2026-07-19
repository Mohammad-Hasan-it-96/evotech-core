<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Carries the config catalog into the new tables.
 *
 * This runs inside the schema migration rather than a seeder on purpose: the deploy
 * pipeline runs `migrate --force` but never `db:seed`, so a separate seeder would
 * leave production with the new code reading empty tables — every device offered
 * zero plans until someone remembered to run it by hand.
 *
 * Idempotent (checked-then-inserted per row), so re-running after a partial failure
 * cannot duplicate a plan key.
 */
return new class extends Migration
{
    /**
     * Which Products-module row each app's releases belong to. The names diverged
     * before the modules were joined — this module calls it 'Fawateer', the product
     * catalog calls it 'invoices' — so the mapping has to be stated, not derived.
     */
    private const PRODUCT_SLUGS = [
        'Fawateer' => 'invoices',
        'SmartAgent' => 'smart-delegate',
    ];

    public function up(): void
    {
        $now = now();

        $apps = config('device-subscriptions.apps', []);
        $apps = is_array($apps) ? $apps : [];

        foreach ($apps as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                continue;
            }

            if (DB::table('device_apps')->where('name', $name)->exists()) {
                continue;
            }

            $slug = $settings['slug'] ?? null;
            $label = $settings['label'] ?? null;
            $trialDays = $settings['trial_days'] ?? 0;

            // An app's own catalog if it configured one; null means it reads the
            // shared list, which is what both apps do today.
            $ownPlans = $settings['plans'] ?? null;
            $ownPlans = is_array($ownPlans) ? array_values($ownPlans) : null;

            $productSlug = self::PRODUCT_SLUGS[$name] ?? null;

            // Tolerates a missing product: fresh test databases have no product
            // catalog, and the link is not required for plans to work.
            $productId = $productSlug === null
                ? null
                : DB::table('products')->where('slug', $productSlug)->value('id');

            $appId = DB::table('device_apps')->insertGetId([
                'uuid' => Uuid::uuid7()->toString(),
                'name' => $name,
                'slug' => is_string($slug) && $slug !== '' ? $slug : strtolower($name),
                'label' => is_string($label) && $label !== '' ? $label : $name,
                'trial_days' => is_numeric($trialDays) ? max(0, (int) $trialDays) : 0,
                'uses_shared_plans' => $ownPlans === null,
                'product_id' => $productId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($ownPlans !== null) {
                $this->insertPlans($ownPlans, $appId, $now);
            }
        }

        $shared = config('device-subscriptions.plans', []);
        $this->insertPlans(is_array($shared) ? array_values($shared) : [], null, $now);
    }

    public function down(): void
    {
        DB::table('device_plans')->delete();
        DB::table('device_apps')->delete();
    }

    /**
     * @param  array<int, mixed>  $plans
     */
    private function insertPlans(array $plans, ?int $appId, mixed $now): void
    {
        foreach ($plans as $index => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $key = $plan['id'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $exists = DB::table('device_plans')
                ->where('plan_key', $key)
                ->when($appId === null,
                    fn ($query) => $query->whereNull('device_app_id'),
                    fn ($query) => $query->where('device_app_id', $appId),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            $discount = $plan['price_after_discount'] ?? null;

            DB::table('device_plans')->insert([
                'uuid' => Uuid::uuid7()->toString(),
                'device_app_id' => $appId,
                // Verbatim from config — live device rows hold this string.
                'plan_key' => $key,
                'title' => (string) ($plan['title'] ?? $key),
                'description' => isset($plan['description']) ? (string) $plan['description'] : null,
                'duration_months' => (int) ($plan['duration_months'] ?? 0),
                'price' => (float) ($plan['price'] ?? 0),
                'price_after_discount' => is_numeric($discount) ? (float) $discount : null,
                'enabled' => (bool) ($plan['enabled'] ?? true),
                'recommended' => (bool) ($plan['recommended'] ?? false),
                // Preserves the order the config listed them in, which is the order
                // the app renders and therefore what customers have been seeing.
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
