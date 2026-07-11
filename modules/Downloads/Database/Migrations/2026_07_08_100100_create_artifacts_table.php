<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('release_id')->constrained('releases')->cascadeOnDelete();
            $table->string('platform')->default('any');
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum_sha256', 64);
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['release_id', 'platform']);
            $table->index('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
