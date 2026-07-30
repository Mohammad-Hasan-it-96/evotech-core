<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Change-log retention watermark (ADR 0011, Decision 14).
 *
 * `pruned_through_seq` is the highest `device_changes.seq` that has been pruned
 * for this business — everything at or below it is gone from the log; everything
 * above it is retained and contiguous. `pull` uses it to tell a fallen-behind
 * device (cursor < pruned_through_seq) that it has missed changes the log no
 * longer holds, so it must re-bootstrap from a snapshot (§13) rather than
 * conclude "nothing changed". Default 0: a fresh business has pruned nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_businesses', function (Blueprint $table): void {
            $table->unsignedBigInteger('pruned_through_seq')->default(0)->after('last_seq');
        });
    }

    public function down(): void
    {
        Schema::table('device_businesses', function (Blueprint $table): void {
            $table->dropColumn('pruned_through_seq');
        });
    }
};
