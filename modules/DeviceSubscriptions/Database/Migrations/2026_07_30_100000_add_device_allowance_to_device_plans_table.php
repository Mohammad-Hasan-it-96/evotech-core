<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device tier a plan sells (ADR 0011, Decision 3). One subscription = one
 * business owning up to `device_allowance` devices; the shipped tiers are 1/3/5.
 *
 * Until now the allowance lived in a bolt-on `sync.plan_allowance` config map
 * consulted at owner onboarding. Moving it onto the plan makes the tier a
 * first-class, dashboard-provisioned property of the thing a customer buys — so a
 * new tier is created the same way a new price is, with no code deploy.
 *
 * Additive and behaviour-preserving: the column defaults to 1, so every existing
 * plan (half_year, yearly — both single-device today) keeps the exact allowance it
 * had when onboarding fell through to `default_allowance` of 1. It is NOT surfaced
 * in `DevicePlan::toLegacyArray()`, so the shipped `getPlans` wire contract is
 * unchanged — the app has no concept of device counts yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('device_allowance')->default(1)->after('duration_months');
        });
    }

    public function down(): void
    {
        Schema::table('device_plans', function (Blueprint $table): void {
            $table->dropColumn('device_allowance');
        });
    }
};
