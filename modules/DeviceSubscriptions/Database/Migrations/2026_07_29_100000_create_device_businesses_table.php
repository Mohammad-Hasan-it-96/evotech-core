<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The multi-device subscription owner (ADR 0011). One business owns the
 * subscription and up to `device_allowance` devices; each device is a seat
 * (device_seats). This is the new owning aggregate the platform lacked — a
 * device_subscriptions row *was* one device *was* one subscription, and "one
 * subscription owns N devices" has nowhere to live without it.
 *
 * Deliberately NON-TENANT, like the rest of DeviceSubscriptions: a consumer shop
 * has no Company and no human login. This is NOT `Companies` — it is the scoping
 * key for every piece of sync data, and cross-business isolation is the feature's
 * paramount security property (§10). It is not a device row either.
 *
 * `last_seq` is the per-business monotonic cursor source (§7/§8): incremented
 * under a row lock inside the change-insert transaction so pull can never skip a
 * row. Subscription state (is_verified/expires_at/plan_id) lives here, not on the
 * member device rows, once a device is enrolled (business_id set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_businesses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A business belongs to one app (each app is its own product + Firebase
            // project). Matches device_subscriptions.app_name exactly as sent.
            $table->string('app_name', 50);

            // Authoritative subscription state for the member devices. Mirrors the
            // device_subscriptions columns so an enrolled device reads business
            // expiry/verified with no shape change (§2).
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('trial_expires_at')->nullable();
            $table->string('plan_id', 50)->nullable();

            // Seats the plan permits. Enforced at the business level, never per
            // device (a device is disposable hardware) — tiers 1/3/5 are presets
            // over this integer, so re-cutting them later is pricing, not migration.
            $table->unsignedInteger('device_allowance')->default(1);

            // The per-business pull cursor high-water mark. Handed out in commit
            // order under a row lock (§8); a global auto-increment would skip rows.
            $table->unsignedBigInteger('last_seq')->default(0);

            $table->timestamps();

            $table->index('app_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_businesses');
    }
};
