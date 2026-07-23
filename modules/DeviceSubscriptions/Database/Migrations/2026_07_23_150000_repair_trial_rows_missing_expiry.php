<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair trial rows whose expiry was erased, and forbid the state going forward.
 *
 * The 2026-07-22 legacy import upserted app_harfoshs rows over devices that had
 * already self-registered here and been granted a trial. The legacy table has no
 * trial column, so the upsert overwrote `expires_at` with the legacy value —
 * NULL for a hand-verified legacy test device — while `trial_expires_at`
 * survived. Since NULL expiry means "lifetime", the trial device became verified
 * forever (confirmed in production on a Fawateer device).
 *
 * The repair restores the trial's own expiry, scoped to plan-less rows so a paid
 * lifetime subscription (NULL expiry with a plan_id) is never touched. On MySQL
 * the same invariant then becomes a CHECK constraint, so the next hand-run
 * import that tries to write the state fails loudly at the database instead of
 * silently minting an eternal subscription. SQLite (the test driver) cannot add
 * a constraint to an existing table; there the model-level guards carry the
 * invariant alone.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'chk_trial_rows_keep_expiry';

    public function up(): void
    {
        $repaired = DB::table('device_subscriptions')
            ->whereNull('expires_at')
            ->whereNotNull('trial_expires_at')
            ->whereNull('plan_id')
            ->update(['expires_at' => DB::raw('trial_expires_at')]);

        if ($repaired > 0) {
            Log::info("Repaired {$repaired} trial device(s) whose expiry had been erased.");
        }

        if (DB::getDriverName() === 'mysql' && ! $this->constraintExists()) {
            DB::statement(
                'ALTER TABLE device_subscriptions ADD CONSTRAINT '.self::CONSTRAINT
                .' CHECK (trial_expires_at IS NULL OR plan_id IS NOT NULL OR expires_at IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        // The data repair is deliberately not reversed — the pre-repair state was
        // corruption, not history.
        if (DB::getDriverName() === 'mysql' && $this->constraintExists()) {
            DB::statement('ALTER TABLE device_subscriptions DROP CONSTRAINT '.self::CONSTRAINT);
        }
    }

    /** Existence check keeps up() re-runnable (tests invoke it directly). */
    private function constraintExists(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'device_subscriptions')
            ->where('CONSTRAINT_NAME', self::CONSTRAINT)
            ->exists();
    }
};
