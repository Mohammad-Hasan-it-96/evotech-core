<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DeviceSubscriptions\Application\DTO\AppliedChange;
use Modules\DeviceSubscriptions\Application\DTO\PullPage;
use Modules\DeviceSubscriptions\Application\DTO\PushResult;
use Modules\DeviceSubscriptions\Domain\Exceptions\SyncException;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Domain\Models\DeviceChange;

/**
 * The per-business change log — push and pull (ADR 0011, §7–§9).
 *
 * The two clocks are kept strictly apart here. `seq` is server-assigned under a
 * per-business row lock, so it is monotonic and gap-free per business and is the
 * ONLY thing paging uses. `authored_hlc` is the device clock, carried through
 * untouched and used ONLY for conflict resolution — never for paging. The
 * server stores an append-only oplog; devices resolve last-writer-wins locally.
 */
final class SyncChangeService
{
    /**
     * Record a batch of changes for a business and assign each a server seq.
     *
     * Serialized per business by a row lock, so two devices pushing at once get
     * disjoint, monotonic seqs and never collide. Idempotent per row (§F2): a
     * re-push of the exact same edit (same row_uuid + authored_hlc) no-ops and
     * returns the seq already assigned, so a client retry is safe.
     *
     * The `origin_device` is taken from the AUTHENTICATED seat's node id, never
     * from the request body — that is what makes the no-echo pull filter and
     * attribution trustworthy (Decision 4).
     *
     * @param  list<array{row_uuid: string, table_name: string, op?: string, authored_hlc: string, payload?: array<array-key, mixed>|null}>  $changes
     */
    public function push(int $businessId, string $originDevice, array $changes): PushResult
    {
        return DB::transaction(function () use ($businessId, $originDevice, $changes): PushResult {
            // Lock the business row for the life of the transaction: this is the
            // mutex that serialises seq assignment across concurrent pushers.
            $business = DeviceBusiness::query()
                ->whereKey($businessId)
                ->lockForUpdate()
                ->firstOrFail();

            $seq = $business->last_seq;
            $applied = [];

            foreach ($changes as $change) {
                $rowUuid = $change['row_uuid'];
                $authoredHlc = $change['authored_hlc'];
                $idempotencyKey = DeviceChange::idempotencyKey($rowUuid, $authoredHlc);

                // Uncommitted inserts from earlier in THIS transaction are visible
                // to this lookup, so a duplicate within one batch also no-ops.
                $existing = DeviceChange::query()
                    ->where('device_business_id', $businessId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $applied[] = new AppliedChange($rowUuid, $authoredHlc, $existing->seq, true);

                    continue;
                }

                $seq++;

                DeviceChange::query()->create([
                    'device_business_id' => $businessId,
                    'seq' => $seq,
                    'row_uuid' => $rowUuid,
                    'table_name' => $change['table_name'],
                    'op' => $change['op'] ?? DeviceChange::OP_UPSERT,
                    'origin_device' => $originDevice,
                    'authored_hlc' => $authoredHlc,
                    'idempotency_key' => $idempotencyKey,
                    'payload' => $change['payload'] ?? null,
                ]);

                $applied[] = new AppliedChange($rowUuid, $authoredHlc, $seq, false);
            }

            if ($seq !== $business->last_seq) {
                $business->forceFill(['last_seq' => $seq])->save();
            }

            return new PushResult($applied, $seq);
        });
    }

    /**
     * Return one page of changes a device has not yet seen (§9).
     *
     * The watermark is the highest seq EXAMINED, not the highest RETURNED: we
     * scan a contiguous window of seqs above the cursor and advance `nextCursor`
     * to the top of that window, then drop the caller's own echoed rows from what
     * we return. A page made entirely of the device's own changes therefore still
     * advances the cursor instead of being re-served forever.
     */
    public function pull(int $businessId, string $originDevice, int $cursor, int $limit): PullPage
    {
        // Fallen off the retained window (§14): the device's cursor sits below the
        // pruned watermark, so the changes it still needs (cursor+1 … pruned) are
        // gone from the log. "Nothing changed" and "you fell off the log" must not
        // look identical, so this is a distinct signal, never an empty page — the
        // client re-seeds from a fresh snapshot (§13) instead of silently diverging.
        $prunedThrough = DeviceBusiness::query()
            ->whereKey($businessId)
            ->firstOrFail()
            ->pruned_through_seq;

        if ($cursor < $prunedThrough) {
            throw SyncException::cursorTooOld();
        }

        // Examine a contiguous window by seq; fetch one extra to detect hasMore.
        $window = DeviceChange::query()
            ->where('device_business_id', $businessId)
            ->where('seq', '>', $cursor)
            ->orderBy('seq')
            ->limit($limit + 1)
            ->get();

        $hasMore = $window->count() > $limit;
        $examined = $window->take($limit);

        if ($examined->isEmpty()) {
            return new PullPage([], $cursor, false);
        }

        // Highest seq we looked at — the watermark, regardless of what we return.
        $nextCursor = (int) $examined->last()->seq;

        // No-echo: never hand a device back its own changes (§9).
        $changes = array_values(
            $examined->reject(fn (DeviceChange $c): bool => $c->origin_device === $originDevice)->all()
        );

        return new PullPage($changes, $nextCursor, $hasMore);
    }

    /**
     * The winning version of a row under last-writer-wins: the change with the
     * highest `authored_hlc` (§7). HLC strings sort lexicographically, so a later
     * edit always wins even if a stale older edit arrived (and got a higher seq)
     * afterwards — proving seq never leaks into conflict resolution.
     */
    public function latestForRow(int $businessId, string $rowUuid): ?DeviceChange
    {
        return DeviceChange::query()
            ->where('device_business_id', $businessId)
            ->where('row_uuid', $rowUuid)
            ->orderByDesc('authored_hlc')
            ->first();
    }

    /**
     * Prune a business's change log of everything older than `$cutoff` (ADR 0011,
     * Decision 14). Returns the number of rows removed.
     *
     * Deletes by `seq <= maxOldSeq` — the highest seq among the too-old rows —
     * NOT by timestamp directly, so the retained set stays a **contiguous** range
     * above `pruned_through_seq`. That contiguity is exactly what `pull`'s
     * cursor-too-old check relies on: everything above the watermark is present,
     * so a cursor at or above it can always catch up incrementally. seq order is
     * server-insert order, which is also created_at order, so the two agree.
     *
     * The watermark only ever advances (a re-run with a later cutoff prunes more);
     * it is never lowered, so a device that was told `cursor_too_old` cannot later
     * be told it is fine again.
     */
    public function prune(int $businessId, Carbon $cutoff): int
    {
        return DB::transaction(function () use ($businessId, $cutoff): int {
            $business = DeviceBusiness::query()
                ->whereKey($businessId)
                ->lockForUpdate()
                ->firstOrFail();

            $maxOldSeq = DeviceChange::query()
                ->where('device_business_id', $businessId)
                ->where('created_at', '<', $cutoff)
                ->max('seq');

            // Null (no too-old rows), or a non-numeric driver result — nothing to do.
            if (! is_numeric($maxOldSeq)) {
                return 0;
            }

            $maxSeq = (int) $maxOldSeq;

            $deleted = DeviceChange::query()
                ->where('device_business_id', $businessId)
                ->where('seq', '<=', $maxSeq)
                ->delete();

            if ($maxSeq > $business->pruned_through_seq) {
                $business->forceFill(['pruned_through_seq' => $maxSeq])->save();
            }

            return is_int($deleted) ? $deleted : 0;
        });
    }
}
