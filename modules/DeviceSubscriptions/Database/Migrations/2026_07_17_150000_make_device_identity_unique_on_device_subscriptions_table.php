<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make (app_name, device_id) unique — the anti-farm anchor, enforced.
 *
 * The free trial is unfarmable only because "a reinstall finds the existing row"
 * (registerOrTouch grants a trial solely on creation). That invariant was upheld
 * by application code alone — a find-then-create with a gap between the two, over
 * a merely-indexed pair. Two concurrent create_device calls for the same new
 * device (a double-tapped Activate is enough) both see nothing and both insert.
 *
 * Duplicates would not lengthen a trial — both rows carry the same expiry — but
 * they split a device's identity in two, and lookups pick with an unordered
 * first(): the operator could activate one row while the app reads the other, so
 * a paying customer stays locked out while the console reports success. The pair
 * is the identity; the database should say so.
 *
 * The plain index is dropped: a unique index answers the same lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstDuplicates();

        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['app_name', 'device_id']);
            $table->unique(['app_name', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['app_name', 'device_id']);
            $table->index(['app_name', 'device_id']);
        });
    }

    /**
     * Refuse rather than guess.
     *
     * Adding the constraint over existing duplicates would throw mid-migration; the
     * tempting fix is to delete all but one row first. We don't: these rows carry
     * paid subscriptions, and "keep the lowest id" could silently discard the one
     * that was actually activated, taking a customer's licence with it. There should
     * be none of these (the legacy import upserts on exactly this pair), so this
     * should never fire — and if it does, a human should decide which row survives.
     */
    private function guardAgainstDuplicates(): void
    {
        $duplicates = DB::table('device_subscriptions')
            ->select('app_name', 'device_id', DB::raw('COUNT(*) as total'))
            ->groupBy('app_name', 'device_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->map(fn ($row): string => sprintf('%s/%s (%d rows)', $row->app_name, $row->device_id, $row->total))
            ->implode(', ');

        throw new RuntimeException(
            'Cannot make (app_name, device_id) unique: duplicate device rows exist. '
            .'Merge them by hand — keep the row holding the live subscription, and do '
            .'not assume it is the oldest. Duplicates: '.$detail
        );
    }
};
