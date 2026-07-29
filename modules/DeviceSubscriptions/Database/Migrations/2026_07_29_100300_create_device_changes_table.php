<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The per-business change log — the sync oplog (ADR 0011, Decisions 7-9).
 *
 * Append-only. Each row is one change a device authored, carrying TWO distinct
 * clocks (§7):
 *   - `seq`  — server-assigned, per-business, monotonic; drives the PULL CURSOR
 *     only. Handed out under a row lock on the business (§8) so it is strictly in
 *     commit order and a puller can never advance past an uncommitted lower seq.
 *   - `authored_hlc` — a device-authored Hybrid Logical Clock; drives CONFLICT
 *     RESOLUTION only. Stored as a STRING, not a number: it packs to
 *     `<millis:15>-<counter:5>-<nodeId:16>` and sorts lexicographically in clock
 *     order, so `ORDER BY authored_hlc` / `WHERE authored_hlc > ?` are correct on
 *     a plain string column and the server never parses it. VARCHAR(40) = 38 chars
 *     of content; the node id is 16 hex (§3).
 *
 * Idempotency is PER ROW, not per batch (§9/F2): `idempotency_key` = the row uuid
 * plus its authored HLC, so the same edit re-pushed is byte-identical and no-ops,
 * while a later edit to the same row has a new HLC and is correctly a new change.
 * `unique(business_id, idempotency_key)` makes retry-after-partial-success safe.
 *
 * The server keeps these as opaque blobs for the append-only financial tables
 * (invoices, ledger, cashbox) and never parses their totals (§10/§11). `payload`
 * is the change body the client applies locally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('device_business_id')
                ->constrained('device_businesses')
                ->cascadeOnDelete();

            // Per-business pull cursor. Assigned under a business row lock (§8).
            $table->unsignedBigInteger('seq');

            // The business-row identity this change concerns (the client's sync
            // identity for the row — never a device-local autoincrement id).
            $table->uuid('row_uuid');

            // Which logical table the row belongs to (e.g. 'products', 'sales_items').
            $table->string('table_name', 40);

            // 'upsert' | 'delete' (a delete is a tombstone, not a hard removal).
            $table->string('op', 10)->default('upsert');

            // The authoring device's HLC node id (16 hex). The no-echo pull filter
            // excludes rows whose origin matches the caller's node id.
            $table->string('origin_device', 16);

            // Device-authored HLC (see file header). Drives last-writer-wins.
            $table->string('authored_hlc', 40);

            // row_uuid + '|' + authored_hlc (§F2). Per-row idempotency.
            $table->string('idempotency_key', 96);

            // The change body the client applies. Opaque to the server for the
            // append-only financial tables.
            $table->json('payload')->nullable();

            // Append-only: creation time only, no updated_at.
            $table->timestamp('created_at')->nullable();

            // Pull ordering and the no-skip guarantee.
            $table->unique(['device_business_id', 'seq']);
            // Per-row idempotency — the retry-safe de-dup (§F2).
            $table->unique(['device_business_id', 'idempotency_key']);
            $table->index(['device_business_id', 'seq']);
            // Last-writer-wins resolution for a given row within a business.
            $table->index(['device_business_id', 'row_uuid', 'authored_hlc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_changes');
    }
};
