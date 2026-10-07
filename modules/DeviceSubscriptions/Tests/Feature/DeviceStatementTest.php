<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Application\Services\DeviceStatementService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceStatement;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Public read-only statement links (ADR 0013) for دفتر حسابات.
 *
 * The data is a third party's, so the properties that matter: only whitelisted keys
 * are stored, the token is stored hashed, and every "not there" is the same 404.
 */
class DeviceStatementTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'daftar_hesabat';

    protected function setUp(): void
    {
        parent::setUp();

        DeviceSubscription::factory()->create(['app_name' => self::APP, 'device_id' => 'shop-a']);
        DeviceSubscription::factory()->create(['app_name' => self::APP, 'device_id' => 'shop-b']);
    }

    /**
     * @param  array<string, mixed>  $firstEntry  merged into the first entry
     * @param  array<string, mixed>  $extra  merged into the statement
     * @return array<string, mixed>
     */
    private function statement(int $entries = 2, array $firstEntry = [], array $extra = []): array
    {
        $rows = array_map(fn (int $i): array => [
            'date' => '2026-10-0'.(1 + $i % 9),
            'direction' => $i % 2 === 0 ? 'due' : 'paid',
            'amount' => 25,
            'note' => 'سكر',
        ], range(0, $entries - 1));
        $rows[0] = [...$rows[0], ...$firstEntry];

        return [
            ...$extra,
            'shop_name' => 'بقالة الأمل',
            'customer_name' => 'أبو محمد',
            'currency' => 'ليرة جديدة',
            'balance' => 150.5,
            'total_due' => 200.5,
            'total_paid' => 50,
            'generated_at' => '2026-10-07T12:00:00Z',
            'entries' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<JsonResponse>
     */
    private function share(string $deviceId = 'shop-a', array $extra = [], string $app = self::APP): TestResponse
    {
        return $this->postJson('/api/daftar/statements', [
            'app_name' => $app,
            'device_id' => $deviceId,
            'statement' => $this->statement(),
            ...$extra,
        ]);
    }

    private function token(string $deviceId = 'shop-a'): string
    {
        $token = $this->share($deviceId)->assertCreated()->json('token');
        $this->assertIsString($token);

        return $token;
    }

    public function test_sharing_returns_a_thirty_day_link_to_the_web_page(): void
    {
        $this->freezeSecond();

        $response = $this->share()->assertCreated();
        $token = $response->json('token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $response
            ->assertJsonPath('url', 'https://evotech-sys.com/ar/s/'.$token)
            ->assertJsonPath('expires_at', Carbon::now()->addDays(30)->toISOString());
    }

    public function test_the_public_read_returns_the_snapshot_and_is_not_cached(): void
    {
        $token = $this->token();

        $this->getJson("/api/v1/statements/{$token}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.shop_name', 'بقالة الأمل')
            ->assertJsonPath('data.customer_name', 'أبو محمد')
            ->assertJsonPath('data.balance', 150.5)
            ->assertJsonPath('data.entries.1.direction', 'paid')
            ->assertJsonPath('data.entries.0.note', 'سكر');
    }

    public function test_only_whitelisted_keys_are_stored_and_the_token_is_hashed(): void
    {
        $payload = $this->statement(firstEntry: ['phone' => '0999123456'], extra: ['phone' => '0999123456']);

        $token = $this->postJson('/api/daftar/statements', [
            'app_name' => self::APP,
            'device_id' => 'shop-a',
            'statement' => $payload,
        ])->assertCreated()->json('token');
        $this->assertIsString($token);

        $row = DeviceStatement::query()->sole();
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString('0999123456', (string) json_encode($row->payload));
        // Canonicalized: MySQL's JSON type does not keep object key order.
        $this->assertEqualsCanonicalizing(
            ['shop_name', 'customer_name', 'currency', 'balance', 'total_due', 'total_paid', 'generated_at', 'entries'],
            array_keys($row->payload),
        );
    }

    /**
     * Defence in depth: the validator already drops unvalidated keys, but the service
     * must not rely on its caller for that — it rebuilds the snapshot itself.
     */
    public function test_the_service_whitelists_even_when_called_directly(): void
    {
        $payload = $this->statement(firstEntry: ['device_id' => 'shop-a'], extra: ['phone' => '0999123456']);

        app(DeviceStatementService::class)->create(
            DeviceSubscription::query()->where('device_id', 'shop-a')->sole(),
            $payload,
        );

        $stored = (string) json_encode(DeviceStatement::query()->sole()->payload);
        $this->assertStringNotContainsString('0999123456', $stored);
        $this->assertStringNotContainsString('shop-a', $stored);
    }

    public function test_unknown_and_expired_links_are_the_same_404(): void
    {
        $token = $this->token();

        $this->getJson('/api/v1/statements/'.str_repeat('x', 43))->assertNotFound();

        $this->travel(31)->days();
        $this->getJson("/api/v1/statements/{$token}")->assertNotFound();
    }

    public function test_the_daily_prune_deletes_only_expired_links(): void
    {
        $this->token();
        $this->travel(31)->days();
        $fresh = $this->token();

        $this->assertSame(0, Artisan::call('device-subscriptions:prune-statements'));

        $this->assertSame(1, DeviceStatement::query()->count());
        $this->getJson("/api/v1/statements/{$fresh}")->assertOk();
    }

    public function test_only_the_creating_device_can_revoke(): void
    {
        $token = $this->token();

        $this->deleteJson("/api/daftar/statements/{$token}", ['app_name' => self::APP, 'device_id' => 'shop-b'])
            ->assertNotFound();
        $this->getJson("/api/v1/statements/{$token}")->assertOk();

        $this->deleteJson("/api/daftar/statements/{$token}", ['app_name' => self::APP, 'device_id' => 'shop-a'])
            ->assertNoContent();
        $this->getJson("/api/v1/statements/{$token}")->assertNotFound();
        $this->assertSame(0, DeviceStatement::query()->count());
    }

    public function test_apps_without_the_feature_unknown_devices_and_the_fallback_get_404(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'fawateer', 'device_id' => 'fawateer-dev']);
        DeviceSubscription::factory()->create([
            'app_name' => self::APP,
            'device_id' => 'c7a2909940db38ce0aae5f5a07deb0297125ac323da7fb866eca19a77621c7ea',
        ]);

        $this->share('fawateer-dev', app: 'fawateer')->assertNotFound();
        $this->share('never-registered')->assertNotFound();
        $this->share('c7a2909940db38ce0aae5f5a07deb0297125ac323da7fb866eca19a77621c7ea')->assertNotFound();

        $this->assertSame(0, DeviceStatement::query()->count());
    }

    public function test_the_snapshot_is_validated(): void
    {
        $this->share(extra: ['statement' => $this->statement(301)])->assertStatus(422);

        $this->share(extra: ['statement' => $this->statement(firstEntry: ['direction' => 'gift'])])
            ->assertStatus(422);

        $this->assertSame(0, DeviceStatement::query()->count());
    }

    public function test_deleting_the_device_deletes_its_links(): void
    {
        $token = $this->token();

        DeviceSubscription::query()->where('device_id', 'shop-a')->sole()->delete();

        $this->assertSame(0, DeviceStatement::query()->count());
        $this->getJson("/api/v1/statements/{$token}")->assertNotFound();
    }

    // ─── Staff console ──────────────────────────────────────────────────────────

    private function staff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function shopA(): DeviceSubscription
    {
        return DeviceSubscription::query()->where('device_id', 'shop-a')->sole();
    }

    public function test_the_console_is_staff_only(): void
    {
        $this->getJson("/api/v1/device-subscriptions/{$this->shopA()->uuid}/statements")->assertUnauthorized();

        $this->token();
        $id = DeviceStatement::query()->sole()->uuid;
        $this->deleteJson("/api/v1/device-statements/{$id}")->assertUnauthorized();
        $this->assertSame(1, DeviceStatement::query()->count());
    }

    public function test_the_listing_counts_live_links_per_device(): void
    {
        $this->token();
        $this->travel(31)->days();
        $this->token();
        $this->token();
        $this->staff();

        $this->getJson('/api/v1/device-subscriptions?app_name='.self::APP)
            ->assertOk()
            ->assertJsonFragment(['device_id' => 'shop-a', 'statements_count' => 2]);
    }

    public function test_staff_see_who_and_when_but_not_the_amounts(): void
    {
        $this->token();
        $this->staff();

        $row = $this->getJson("/api/v1/device-subscriptions/{$this->shopA()->uuid}/statements")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'أبو محمد')
            ->assertJsonPath('data.0.currency', 'ليرة جديدة')
            ->assertJsonPath('data.0.entries_count', 2)
            ->json('data.0');

        $this->assertIsArray($row);
        $this->assertEqualsCanonicalizing(
            ['id', 'customer_name', 'currency', 'entries_count', 'created_at', 'expires_at'],
            array_keys($row),
        );
    }

    public function test_staff_can_stop_a_link_and_the_audit_keeps_no_content(): void
    {
        $token = $this->token();
        $this->staff();
        $id = DeviceStatement::query()->sole()->uuid;

        $this->deleteJson("/api/v1/device-statements/{$id}")->assertNoContent();

        $this->getJson("/api/v1/statements/{$token}")->assertNotFound();
        $context = DB::table('audit_logs')->where('action', 'device_statement.deleted')->value('context');
        $this->assertIsString($context);
        $this->assertStringNotContainsString('أبو محمد', $context);
        $this->assertStringNotContainsString('150', $context);
    }
}
