<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A of the app-API roadmap (docs/ROADMAP-APP-APIS.md).
 *
 * The shipped Fawateer app files a purchase intent through create_device
 * (requested_plan + contact_method + status:'pending') and expects an is_trial
 * flag back. None of that had anywhere to live. Columns are nullable so legacy
 * app_harfoshs rows import unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            // Plan-request state filed by the app; drives the operator console.
            $table->string('status', 20)->nullable()->after('is_verified');
            $table->string('requested_plan', 50)->nullable()->after('plan_id');
            $table->string('contact_method', 30)->nullable()->after('requested_plan');

            // Records that a trial was granted. Kept even after conversion to a paid
            // plan: it is the anti-abuse anchor that stops a reinstall re-trialling.
            $table->timestamp('trial_expires_at')->nullable()->after('expires_at');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'requested_plan', 'contact_method', 'trial_expires_at']);
        });
    }
};
