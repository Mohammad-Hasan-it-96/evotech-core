<?php

namespace Modules\DeviceSubscriptions\Http\Controllers\Sync;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Modules\Core\Http\Responses\ApiResponse;
use Modules\DeviceSubscriptions\Application\DTO\AppliedChange;
use Modules\DeviceSubscriptions\Application\Services\SyncChangeService;
use Modules\DeviceSubscriptions\Application\Services\SyncDoorbell;
use Modules\DeviceSubscriptions\Domain\Contracts\SyncContext;
use Modules\DeviceSubscriptions\Domain\Models\DeviceChange;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;

/**
 * The change-log endpoints — push and pull (ADR 0011, §7–§9). Both are scoped to
 * the authenticated seat's business; the origin device is always the caller's own
 * node id, taken from the guard and never from the body (Decision 4).
 */
final class SyncChangeController extends SyncController
{
    public function __construct(
        SyncContext $context,
        private readonly SyncChangeService $changes,
        private readonly SyncDoorbell $doorbell,
    ) {
        parent::__construct($context);
    }

    /** POST /api/v1/sync/changes — record a batch of local edits. */
    public function push(Request $request): JsonResponse
    {
        $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:500'],
            'changes.*.row_uuid' => ['required', 'string', 'max:64'],
            'changes.*.table_name' => ['required', 'string', 'max:40'],
            'changes.*.op' => ['nullable', 'in:upsert,delete'],
            'changes.*.authored_hlc' => ['required', 'string', 'max:40'],
            'changes.*.payload' => ['nullable', 'array'],
            'push_token' => ['nullable', 'string', 'max:255'],
        ]);

        $businessId = $this->businessId();
        $seatId = $this->seatId();

        // Keep the caller's own FCM token fresh so future doorbells reach it.
        if ($request->filled('push_token')) {
            DeviceSeat::query()->whereKey($seatId)->update(['push_token' => (string) $request->string('push_token')]);
        }

        $result = $this->changes->push($businessId, $this->nodeId(), $this->changesFrom($request));

        // Best-effort: wake the siblings so they pull within seconds (§doorbell).
        $this->doorbell->ring($businessId, $seatId, $this->appName());

        return ApiResponse::success([
            'applied' => array_map(fn (AppliedChange $a): array => [
                'row_uuid' => $a->rowUuid,
                'authored_hlc' => $a->authoredHlc,
                'seq' => $a->seq,
                'duplicate' => $a->duplicate,
            ], $result->applied),
            'last_seq' => $result->lastSeq,
        ], status: 201);
    }

    /** GET /api/v1/sync/changes — pull changes this device has not yet seen. */
    public function pull(Request $request): JsonResponse
    {
        $maxLimit = Config::integer('device-subscriptions.sync.pull_max_limit');
        $defaultLimit = Config::integer('device-subscriptions.sync.pull_limit');

        $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$maxLimit],
        ]);

        return $this->guardSync(function () use ($request, $defaultLimit): JsonResponse {
            $page = $this->changes->pull(
                $this->businessId(),
                $this->nodeId(),
                $request->integer('cursor'),
                $request->integer('limit', $defaultLimit),
            );

            return ApiResponse::success(
                array_map(fn (DeviceChange $c): array => [
                    'seq' => $c->seq,
                    'row_uuid' => $c->row_uuid,
                    'table_name' => $c->table_name,
                    'op' => $c->op,
                    'origin_device' => $c->origin_device,
                    'authored_hlc' => $c->authored_hlc,
                    'payload' => $c->payload,
                ], $page->changes),
                meta: [
                    'next_cursor' => $page->nextCursor,
                    'has_more' => $page->hasMore,
                ],
            );
        });
    }

    /**
     * Normalise the validated `changes` payload into the typed list the service
     * consumes. Validation has already guaranteed the shape; the per-field type
     * guards keep the static analyser honest without trusting the request array.
     *
     * @return list<array{row_uuid: string, table_name: string, op: string, authored_hlc: string, payload: array<array-key, mixed>|null}>
     */
    private function changesFrom(Request $request): array
    {
        $changes = [];

        foreach ($request->array('changes') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $changes[] = [
                'row_uuid' => is_string($row['row_uuid'] ?? null) ? $row['row_uuid'] : '',
                'table_name' => is_string($row['table_name'] ?? null) ? $row['table_name'] : '',
                'op' => is_string($row['op'] ?? null) ? $row['op'] : DeviceChange::OP_UPSERT,
                'authored_hlc' => is_string($row['authored_hlc'] ?? null) ? $row['authored_hlc'] : '',
                'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : null,
            ];
        }

        return $changes;
    }
}
