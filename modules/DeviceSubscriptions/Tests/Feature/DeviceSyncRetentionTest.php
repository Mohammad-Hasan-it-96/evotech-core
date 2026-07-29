<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Modules\DeviceSubscriptions\Application\Services\SyncChangeService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Domain\Models\DeviceChange;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * Change-log retention and the cursor-too-old recovery (ADR 0011, Decision 14).
 * The oplog is pruned to a window; a device whose cursor falls below the pruned
 * watermark is told to re-bootstrap rather than concluding "nothing changed".
 */
class DeviceSyncRetentionTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    /**
     * Record `$count` changes for a business as its owner, returning nothing —
     * the seqs are 1..count.
     */
    private function seedChanges(int $businessId, string $originDevice, int $count): void
    {
        $changes = [];
        for ($i = 1; $i <= $count; $i++) {
            $changes[] = [
                'row_uuid' => "row-{$i}",
                'table_name' => 'invoices',
                'authored_hlc' => "hlc-{$i}",
                'payload' => [],
            ];
        }

        app(SyncChangeService::class)->push($businessId, $originDevice, $changes);
    }

    private function ageChangesThrough(int $businessId, int $seq, int $days): void
    {
        DeviceChange::query()
            ->where('device_business_id', $businessId)
            ->where('seq', '<=', $seq)
            ->update(['created_at' => Carbon::now()->subDays($days)]);
    }

    public function test_pull_signals_cursor_too_old_below_the_pruned_watermark(): void
    {
        $owner = $this->establishOwner();
        $owner->seat->business->forceFill(['pruned_through_seq' => 5])->save();

        // A cursor below the watermark has fallen off the log → re-bootstrap.
        $this->syncGet('/api/v1/sync/changes?cursor=3', $owner->plaintext)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CURSOR_TOO_OLD');

        // A cursor AT the watermark can still catch up incrementally.
        $this->syncGet('/api/v1/sync/changes?cursor=5', $owner->plaintext)->assertOk();

        // …as can a fresh business (watermark 0, cursor 0).
        $fresh = $this->establishOwner(ownerLabel: 'owner-2');
        $this->syncGet('/api/v1/sync/changes', $fresh->plaintext)->assertOk();
    }

    public function test_prune_removes_old_changes_and_advances_the_watermark(): void
    {
        $owner = $this->establishOwner();
        $businessId = $owner->seat->device_business_id;

        $this->seedChanges($businessId, $owner->seat->node_id, 5);
        $this->ageChangesThrough($businessId, 3, days: 100); // seqs 1-3 are old

        $deleted = app(SyncChangeService::class)->prune($businessId, Carbon::now()->subDays(60));

        $this->assertSame(3, $deleted);
        $this->assertSame(2, DeviceChange::query()->where('device_business_id', $businessId)->count());
        $this->assertSame(3, DeviceBusiness::query()->whereKey($businessId)->value('pruned_through_seq'));

        // The watermark only advances — a second run with the same cutoff is a no-op.
        $this->assertSame(0, app(SyncChangeService::class)->prune($businessId, Carbon::now()->subDays(60)));
        $this->assertSame(3, DeviceBusiness::query()->whereKey($businessId)->value('pruned_through_seq'));
    }

    public function test_a_current_device_still_pulls_the_retained_tail_after_a_prune(): void
    {
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');
        $businessId = $owner->seat->device_business_id;

        $this->seedChanges($businessId, $owner->seat->node_id, 5);
        $this->ageChangesThrough($businessId, 3, days: 100);
        app(SyncChangeService::class)->prune($businessId, Carbon::now()->subDays(60));

        // The member, current at the watermark, receives exactly the retained tail.
        $response = $this->syncGet('/api/v1/sync/changes?cursor=3', $member->plaintext)->assertOk();
        $this->assertSame([4, 5], array_column($this->jsonArray($response, 'data'), 'seq'));
    }

    public function test_the_scheduled_command_prunes_old_changes(): void
    {
        Config::set('device-subscriptions.sync.retention_days', 60);

        $owner = $this->establishOwner();
        $businessId = $owner->seat->device_business_id;
        $this->seedChanges($businessId, $owner->seat->node_id, 4);
        $this->ageChangesThrough($businessId, 4, days: 100);

        $this->assertSame(0, Artisan::call('device-subscriptions:prune-sync-changes'));

        $this->assertSame(0, DeviceChange::query()->where('device_business_id', $businessId)->count());
        $this->assertSame(4, DeviceBusiness::query()->whereKey($businessId)->value('pruned_through_seq'));
    }

    public function test_disabled_retention_prunes_nothing(): void
    {
        Config::set('device-subscriptions.sync.retention_days', 0);

        $owner = $this->establishOwner();
        $businessId = $owner->seat->device_business_id;
        $this->seedChanges($businessId, $owner->seat->node_id, 3);
        $this->ageChangesThrough($businessId, 3, days: 100);

        $this->assertSame(0, Artisan::call('device-subscriptions:prune-sync-changes'));

        $this->assertSame(3, DeviceChange::query()->where('device_business_id', $businessId)->count());
        $this->assertSame(0, DeviceBusiness::query()->whereKey($businessId)->value('pruned_through_seq'));
    }
}
