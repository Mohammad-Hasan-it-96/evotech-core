<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `device_changes.row_uuid` from CHAR(36) to VARCHAR(64) (ADR 0011, §8).
 *
 * The column was declared with `$table->uuid(...)`, which assumed every client
 * row identity is a bare UUID. That is not true of Fawateer, and the assumption
 * made the push endpoint unusable for any shop that had ever recorded a sale.
 *
 * Six of its nine replicated tables do key on a v4 uuid (36). Three do not, and
 * in each case the shape is deliberate:
 *
 *   sales_items      '<invoice uuid>-<index>'     38  `sales_items.id` is a
 *                                                     device-local autoincrement
 *                                                     and can never travel; the
 *                                                     uuid column was backfilled
 *                                                     as invoice_id || '-' || id
 *                                                     so the backfill is
 *                                                     re-runnable and two devices
 *                                                     migrating independently
 *                                                     agree on the result.
 *   stock_movements  'stock-<sales_items.uuid>'   44  A sale delivered twice must
 *                                                     insert the SAME movement
 *                                                     rather than deduct stock
 *                                                     twice — the id IS the
 *                                                     idempotency.
 *   stock_movements  'opening-<product id>'       44  One opening balance per
 *                                                     product. Random ids would
 *                                                     let two devices each mint
 *                                                     one and sum to double the
 *                                                     shop's real stock.
 *
 * Laravel fails the whole request on one over-length row, so a single sale line
 * rejected a batch of up to 200 with
 * `422 The changes.N.row_uuid field must not be greater than 36 characters.`
 * Confirmed on production 2026-08-31 from a two-phone field test; the symptom
 * surfaced on the owner's "add a phone" button, because that flow runs a full
 * sync first and refuses to seed a joining device from a database it knows is
 * behind.
 *
 * **Widening only.** No stored value changes and nothing already written can
 * fail to fit, so this is safe to run against production with devices enrolled.
 * 64 leaves headroom for a prefixed composite id without being unbounded; the
 * validation rule in SyncChangeController is raised to match, and the two must
 * stay in step — a column wider than the rule is dead capacity, and a rule wider
 * than the column is a truncation.
 *
 * The idempotency key (`row_uuid` + `authored_hlc`) is a SHA-256 hex digest and
 * is unaffected. `authored_hlc` stays at 40: a packed HLC is
 * 15 + 1 + 5 + 1 + 16 = 38 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_changes', function (Blueprint $table): void {
            $table->string('row_uuid', 64)->change();
        });
    }

    public function down(): void
    {
        // Deliberately NOT narrowed back. By the time this could run, rows
        // longer than 36 characters may already be stored, and MySQL would
        // truncate them silently — losing the identity that the idempotency key
        // and every future pull page are built on. Reversing the validation rule
        // is enough to stop new ones arriving.
    }
};
