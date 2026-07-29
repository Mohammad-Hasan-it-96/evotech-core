<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Single-use enrollment token + bootstrap handoff (ADR 0011, Decisions 5 & 13).
 *
 * The owner device mints a short-TTL, single-use, business-bound join token
 * (rendered as a QR). A joining device presents it once to obtain its durable
 * per-device sync credential.
 *
 * It also carries the BOOTSTRAP HANDOFF so a device enrolling into an existing
 * shop is seeded from a snapshot instead of coming up blank (Decision 13):
 *   - `bootstrap_cursor` — the seq the joiner pulls from. This is the OWNER'S OWN
 *     local pull cursor `C`, read before its VACUUM after a full push+pull — NOT a
 *     server-side last_seq, which the server cannot compute correctly because it
 *     does not know which changes the owner has applied (the 2026-07-29 fix).
 *   - `snapshot_path` — the transient private-disk location of the uploaded
 *     snapshot (ADR 0008 delivery). Deleted on consumption or at TTL; never
 *     retained (Decision 13 non-goal).
 *   - `snapshot_sha256` — the owner-computed hash, echoed to the joiner so the
 *     integrity check is end-to-end owner->joiner, not merely transit (H2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_join_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('device_business_id')
                ->constrained('device_businesses')
                ->cascadeOnDelete();

            // SHA-256 of the single-use token (never the plaintext), Gateway style.
            $table->string('token_hash', 64)->unique();

            // Bootstrap handoff (Decision 13). Null until the owner uploads a
            // snapshot; a first/only device enrolls with cursor 0 and empty seed.
            $table->unsignedBigInteger('bootstrap_cursor')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->string('snapshot_sha256', 64)->nullable();

            // Short TTL (minutes) and single use.
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['device_business_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_join_tokens');
    }
};
