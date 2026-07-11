<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A license's device/domain activations — it consumes at most
        // `licenses.max_activations` active (non-revoked) rows at a time.
        Schema::create('license_activations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('identifier_type');
            $table->string('identifier');
            $table->string('name')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('activated_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // One row per identifier per license: activations are idempotent and a
            // deactivated identifier is reactivated in place rather than duplicated.
            $table->unique(['license_id', 'identifier']);
            $table->index(['license_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
    }
};
