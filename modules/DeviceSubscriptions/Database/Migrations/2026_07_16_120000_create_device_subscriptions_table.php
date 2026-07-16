<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Device subscriptions (ADR 0010) — the successor to the legacy app_harfoshs
 * table. Non-tenant: a consumer device has no company, so there is deliberately
 * no company_id / BelongsToCompany here. Column widths mirror the legacy schema;
 * a HasUuid route key is added per the platform's hybrid-identifier rule (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('app_name', 50)->nullable();
            $table->string('device_id', 200)->nullable();
            $table->string('full_name', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->string('plan_id', 50)->nullable();
            $table->text('fcm_token')->nullable();
            $table->integer('stars')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            // A device is identified by the (app_name, device_id) pair.
            $table->index(['app_name', 'device_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_subscriptions');
    }
};
