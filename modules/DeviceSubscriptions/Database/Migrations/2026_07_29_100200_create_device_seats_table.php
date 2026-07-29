<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A device's seat in a business — and its per-device sync credential (ADR 0011,
 * Decisions 4-5). One seat per enrolled device.
 *
 * The credential copies Gateway's proven design (ADR 0004): a token shown once at
 * enrollment, stored only as a SHA-256 hash, with a clear-text prefix for display
 * and a `revoked_at` kill switch. It is keyed per DEVICE (not per product like
 * Gateway), because that is what isolates one business's financial records from
 * every other — the single most important security property of the feature.
 *
 * Why a new credential at all: neither existing guard fits. `auth:sanctum` is for
 * humans; `auth:product` is one shared key per product, so every Fawateer install
 * would present the same key and could not be told apart. This one resolves a
 * `business_id` server-side, and any business/app_name in a request body is
 * ignored for authorization.
 *
 * `node_id` is the first 16 hex chars of the device id (the client's HLC node id,
 * §3/§7). It is stored so the no-echo pull filter can compare it against
 * device_changes.origin_device without re-deriving it per request.
 *
 * Seat count = live COUNT of non-revoked rows per business (Decision 3), which is
 * what `index(device_business_id, revoked_at)` serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_seats', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('device_business_id')
                ->constrained('device_businesses')
                ->cascadeOnDelete();

            // The device identity, matching device_subscriptions' pair.
            $table->string('app_name', 50);
            $table->string('device_id', 200);

            // The client HLC node id: SUBSTR(device_id, 1, 16). The no-echo filter
            // compares device_changes.origin_device against this.
            $table->string('node_id', 16);

            // owner | member. The only asymmetry is subscription ownership: the
            // owner mints/revokes join tokens and manages the plan. Business writes
            // are NOT role-gated (Decision 6) — this exists so a future restriction
            // is a one-seam policy change, and so the owner seat can be protected
            // from revocation (Decision 5, R1).
            $table->string('role', 10)->default('member');

            // Gateway-pattern credential: clear prefix for display, SHA-256 of the
            // full token for O(1) lookup, never the plaintext.
            $table->string('prefix', 24)->unique();
            $table->string('token_hash', 64)->unique();

            // The device's current FCM token, refreshed on enroll/push. Lets a
            // push land the data-only "doorbell" (ADR 0011) on this device's
            // siblings when it writes, so they pull within seconds instead of
            // waiting for the next poll. Nullable: a device with no token still
            // converges by polling — the doorbell only trims latency.
            $table->string('push_token')->nullable();

            $table->timestamp('last_used_at')->nullable();

            // Revocation frees the seat AND (via check_device) stops the device
            // selling — the one place the sync and licensing credentials couple
            // (Decision 5, D). It never repossesses the local data (R2).
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // One seat per device per business; the anti-double-enroll anchor.
            $table->unique(['device_business_id', 'device_id']);
            // Seat counting for allowance, and the revoked-device lookup used by
            // check_device (keyed by the device's own identity).
            $table->index(['device_business_id', 'revoked_at']);
            $table->index(['app_name', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_seats');
    }
};
