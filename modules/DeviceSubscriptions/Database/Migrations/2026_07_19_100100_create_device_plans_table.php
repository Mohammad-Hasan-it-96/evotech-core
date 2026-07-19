<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchasable catalog, previously a literal array in the module config.
 *
 * `plan_key` — NOT the primary key, and deliberately not auto-generated. Live
 * device_subscriptions rows store this string in `plan_id`, and `durationMonths()`
 * resolves a renewal by matching it. A key that changes silently turns a renewal
 * into a 0-month term: instant expiry for someone who has just paid. The seeder
 * therefore carries the existing 'half_year' and 'yearly' keys over verbatim.
 *
 * `device_app_id` nullable = the shared catalog served by the un-namespaced
 * /api/getPlans, which carries no app_name and so cannot resolve an app. Builds
 * still pointed at that URL depend on it, so the shared scope outlives per-app
 * pricing rather than being a migration artifact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('device_app_id')->nullable()->constrained('device_apps')->cascadeOnDelete();

            $table->string('plan_key', 50);
            $table->string('title', 150);
            $table->text('description')->nullable();

            // Drives the expiry date on activation. 0 is rejected at the request
            // layer — it would sell a subscription that expires immediately.
            $table->unsignedSmallInteger('duration_months');

            $table->decimal('price', 10, 2)->default(0);

            // null = no discount; the shipped Fawateer parser reads this key.
            $table->decimal('price_after_discount', 10, 2)->nullable();

            $table->boolean('enabled')->default(true);
            $table->boolean('recommended')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            /*
             * Guards duplicate keys within one app. It does NOT guard the shared
             * scope: SQL treats NULLs as distinct, so two shared plans could both
             * be 'yearly' as far as the index is concerned. That case is enforced
             * in StoreDevicePlanRequest instead — a partial unique index would be
             * the right tool but MySQL has none, and a sentinel id would cost the
             * foreign key.
             */
            $table->unique(['device_app_id', 'plan_key']);

            $table->index(['device_app_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_plans');
    }
};
