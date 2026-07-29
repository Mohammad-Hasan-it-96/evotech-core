<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Modules\DeviceSubscriptions\Application\Services\SyncChangeService;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * The change log — push and pull (ADR 0011, §7–§9). Pins the two clocks staying
 * apart: `seq` is server-assigned, monotonic and the only paging key; `authored_hlc`
 * is the device clock and the only conflict-resolution key. Also covers the no-echo
 * filter, the examined-not-returned watermark, per-business isolation, and the
 * data-only doorbell.
 */
class DeviceSyncPushPullTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    private RecordingPushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new RecordingPushNotifier;
        $this->app->instance(DevicePushNotifier::class, $this->push);
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return TestResponse<JsonResponse>
     */
    private function push(string $token, array $changes, ?string $pushToken = null): TestResponse
    {
        $body = ['changes' => $changes];

        if ($pushToken !== null) {
            $body['push_token'] = $pushToken;
        }

        return $this->syncPost('/api/v1/sync/changes', $body, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function change(string $rowUuid, string $hlc, mixed $payload = []): array
    {
        return [
            'row_uuid' => $rowUuid,
            'table_name' => 'invoices',
            'authored_hlc' => $hlc,
            'payload' => $payload,
        ];
    }

    public function test_push_assigns_monotonic_gapless_seqs(): void
    {
        $owner = $this->establishOwner();

        $response = $this->push($owner->plaintext, [
            $this->change('row-1', 'hlc-001'),
            $this->change('row-2', 'hlc-002'),
            $this->change('row-3', 'hlc-003'),
        ])->assertCreated();

        $this->assertSame([1, 2, 3], array_column($this->jsonArray($response, 'data.applied'), 'seq'));
        $this->assertSame(3, $response->json('data.last_seq'));
    }

    public function test_re_pushing_the_same_edit_is_a_no_op(): void
    {
        $owner = $this->establishOwner();

        $this->push($owner->plaintext, [$this->change('row-1', 'hlc-001')])->assertCreated();

        // Same row + same authored_hlc: byte-identical, so it returns the original
        // seq and adds no new row (§F2). A client retry after a dropped ACK is safe.
        $again = $this->push($owner->plaintext, [$this->change('row-1', 'hlc-001')])->assertCreated();

        $this->assertTrue($again->json('data.applied.0.duplicate'));
        $this->assertSame(1, $again->json('data.applied.0.seq'));
        $this->assertSame(1, $again->json('data.last_seq'));
    }

    public function test_pull_never_echoes_the_callers_own_changes(): void
    {
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');

        $this->push($owner->plaintext, [$this->change('row-1', 'hlc-001')])->assertCreated();

        // The owner authored it, so it is filtered from the owner's own pull…
        $ownerPull = $this->syncGet('/api/v1/sync/changes', $owner->plaintext)->assertOk();
        $this->assertCount(0, $this->jsonArray($ownerPull, 'data'));
        // …but the cursor still advances past it (no re-serve loop).
        $this->assertSame(1, $ownerPull->json('meta.next_cursor'));

        // The sibling device does receive it.
        $memberPull = $this->syncGet('/api/v1/sync/changes', $member->plaintext)->assertOk();
        $this->assertCount(1, $this->jsonArray($memberPull, 'data'));
        $this->assertSame('row-1', $memberPull->json('data.0.row_uuid'));
    }

    public function test_a_page_of_only_the_callers_own_changes_still_advances_the_cursor(): void
    {
        $owner = $this->establishOwner();

        $this->push($owner->plaintext, [
            $this->change('row-1', 'hlc-001'),
            $this->change('row-2', 'hlc-002'),
        ])->assertCreated();

        // Watermark = highest seq EXAMINED, not returned: an all-echo page returns
        // nothing but reports next_cursor=2, so the client never re-requests them.
        $this->syncGet('/api/v1/sync/changes', $owner->plaintext)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.next_cursor', 2)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_two_businesses_never_see_each_others_changes(): void
    {
        $businessA = $this->establishOwner(ownerLabel: 'owner-a');
        $businessB = $this->establishOwner(ownerLabel: 'owner-b');
        $memberA = $this->enrollMember($businessA->seat->business, 'member-a');

        $this->push($businessA->plaintext, [$this->change('a-row', 'hlc-001')])->assertCreated();

        // B's owner sees nothing of A's log (isolation — the paramount property).
        $this->syncGet('/api/v1/sync/changes', $businessB->plaintext)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A's own member does.
        $this->syncGet('/api/v1/sync/changes', $memberA->plaintext)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_last_writer_wins_by_hlc_even_when_the_stale_edit_arrives_later(): void
    {
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');
        $businessId = $owner->seat->device_business_id;

        // The member makes the NEWER edit first (higher HLC), seq 1.
        $this->push($member->plaintext, [$this->change('inv-9', 'hlc-0002', ['total' => 200])])->assertCreated();

        // The owner was offline and pushes a STALE edit to the same row later:
        // lower HLC, but it lands with the HIGHER seq (2).
        $this->push($owner->plaintext, [$this->change('inv-9', 'hlc-0001', ['total' => 100])])->assertCreated();

        // LWW resolves by authored_hlc, never by seq/arrival order — the newer
        // edit wins even though the stale one has the higher server seq.
        $winner = app(SyncChangeService::class)->latestForRow($businessId, 'inv-9');

        $this->assertNotNull($winner);
        $this->assertSame('hlc-0002', $winner->authored_hlc);
        $this->assertSame(200, $winner->payload['total'] ?? null);
    }

    public function test_a_push_rings_the_data_only_doorbell_for_siblings_only(): void
    {
        $owner = $this->establishOwner();
        $owner->seat->forceFill(['push_token' => 'tok-owner'])->save();

        $member = $this->enrollMember($owner->seat->business, 'member', pushToken: 'tok-member');

        $this->push($member->plaintext, [$this->change('row-1', 'hlc-001')], pushToken: 'tok-member')
            ->assertCreated();

        // Exactly one silent, data-only wake — to the sibling owner, never back to
        // the device that just wrote.
        $this->assertCount(1, $this->push->data);
        $this->assertSame('tok-owner', $this->push->data[0]['token']);
        $this->assertSame('sync', $this->push->data[0]['type']);
        $this->assertSame('Fawateer', $this->push->data[0]['app']);
        // And no tray notification was sent (doorbell is data-only).
        $this->assertCount(0, $this->push->sent);
    }
}
