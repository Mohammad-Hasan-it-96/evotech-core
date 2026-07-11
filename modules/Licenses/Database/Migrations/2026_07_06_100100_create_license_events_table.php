<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger (constitution §6): rows are never updated or deleted.
        Schema::create('license_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['license_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
    }
};
