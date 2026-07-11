<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger (constitution §5) for invoice lifecycle events —
        // rows are never updated or deleted.
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
