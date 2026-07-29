<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Link a device row to its multi-device business (ADR 0011, Decision 2).
 *
 * Strictly additive: the column is NULLABLE and every row shipped to date —
 * including Fawateer 1.0.1, running a real shop — leaves it null and behaves
 * exactly as before (its own is_verified/expires_at govern it). Only when set
 * does the business row become the authoritative source of subscription state
 * and seat allowance for that device. Nothing here changes the legacy shim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained('device_businesses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
