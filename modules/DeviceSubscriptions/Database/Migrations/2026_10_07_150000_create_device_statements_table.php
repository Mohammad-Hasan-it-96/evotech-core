<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public read-only statement links (ADR 0013).
 *
 * `token_hash` is SHA-256 of the 43-character token; the plaintext is never stored.
 * Rows are deleted — not soft-deleted — on revoke and by the daily prune after
 * `expires_at`, and cascade with the device that created them: this table holds a
 * third party's debts, so nothing here outlives its purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_statements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_subscription_id')->constrained('device_subscriptions')->cascadeOnDelete();
            $table->string('app_name');
            $table->char('token_hash', 64)->unique();
            $table->json('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_statements');
    }
};
