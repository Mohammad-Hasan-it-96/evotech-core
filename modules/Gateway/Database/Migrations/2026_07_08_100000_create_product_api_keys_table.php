<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-product API keys for product-to-platform (M2M) auth — ADR 0004.
        // Only a hash of the token is stored; the plaintext is shown once.
        Schema::create('product_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16)->unique();
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_api_keys');
    }
};
