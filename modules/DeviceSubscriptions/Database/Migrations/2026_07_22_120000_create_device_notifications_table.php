<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Custom (ad-hoc) device notifications — offers, updates, announcements — and the
 * history of every one sent (ADR 0010). Non-tenant, like the rest of the module.
 *
 * This records the *dispatch*, not per-device delivery: FCM returns no per-token
 * result to store, and a device that was offline still receives the message once
 * it resumes. `recipients` is the number of devices the message was dispatched to
 * (those carrying a push token) — a reach count, not a delivery guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Which app it was sent as — a broadcast targets one app; a test send
            // records the target device's app. Each app is its own Firebase
            // project, so this is never blank for a dispatched notification.
            $table->string('app_name', 50)->nullable();

            // 'test' (one device, before broadcasting) or 'broadcast' (an audience).
            $table->string('scope', 20);

            // Whether a broadcast was narrowed to active subscribers only.
            $table->boolean('active_only')->default(false);

            $table->string('title', 150);
            $table->text('body');

            // Echoed to the client as data.type. Custom sends use one machine key
            // so the apps route them generically without a per-offer contract.
            $table->string('type', 50)->default('custom_message');

            // Devices dispatched to (those with a token). A test is always 1.
            $table->unsignedInteger('recipients')->default(0);

            // For a test send: the device it went to, so the history can name it.
            $table->string('target_device_id', 200)->nullable();

            // Who sent it (staff), captured for the history — the row outlives the
            // token that authorised it.
            $table->string('sent_by', 36)->nullable();
            $table->string('sent_by_name', 100)->nullable();

            $table->timestamps();

            $table->index(['app_name', 'scope']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_notifications');
    }
};
