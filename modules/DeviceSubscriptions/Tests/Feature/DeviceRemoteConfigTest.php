<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\Products\Domain\Models\Product;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * The startup remote-config a shipped app fetches, and the dashboard fields behind
 * it. Contract: docs/api/fawateer-device-contract.md §9.
 *
 * The apps' parsers are defensive but silent — a malformed field degrades to a
 * default rather than throwing — so nothing here would surface as an error in
 * production. It would surface as an update prompt that never fires, or devices
 * quietly talking to the wrong host.
 */
class DeviceRemoteConfigTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function fawateer(): DeviceApp
    {
        return DeviceApp::query()->where('name', 'Fawateer')->sole();
    }

    /** @param TestResponse<JsonResponse> $response */
    private function assertRejectedField(TestResponse $response, string $field): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => $field]);
    }

    /**
     * Pins the payload against the hand-edited file this replaces, exactly as it
     * was being served. Anything else and the cutover changes what a live app sees.
     */
    public function test_the_endpoint_reproduces_the_file_it_replaces(): void
    {
        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertExactJson([
                'latest_version' => '1.0.0',
                'api' => ['base_url' => 'https://api.evotech-sys.com/api/fawateer'],
                'downloads' => [],
                'update_notes' => [],
                'support' => [
                    'email' => 'mohamad.hasan.it.96@gmail.com',
                    'whatsapp' => '963959027196',
                    'telegram' => 'https://t.me/+963959027196',
                ],
            ]);
    }

    public function test_the_config_is_public(): void
    {
        // The app calls this before it has a base URL, let alone a token.
        $this->getJson('/api/fawateer/remote-config')->assertOk();
    }

    public function test_an_unknown_app_is_a_404(): void
    {
        $this->getJson('/api/nosuchapp/remote-config')->assertNotFound();
    }

    /**
     * The invariant the whole builder exists for. An empty base_url makes Fawateer
     * silently keep its compiled-in default and makes SmartAgent *reset* to the
     * legacy host — so an app with nothing configured must still get a real URL.
     */
    public function test_base_url_is_never_empty_even_with_nothing_configured(): void
    {
        config()->set('app.url', 'https://api.evotech-sys.com');

        $this->fawateer()->update([
            'latest_version' => null,
            'api_base_url' => null,
            'support_email' => null,
            'support_whatsapp' => null,
            'support_telegram' => null,
        ]);

        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath('api.base_url', 'https://api.evotech-sys.com/api/fawateer');
    }

    /** Every key is always present: an absent key and an empty one differ. */
    public function test_every_key_is_present_when_nothing_is_configured(): void
    {
        $this->fawateer()->update([
            'latest_version' => null,
            'support_email' => null,
            'support_whatsapp' => null,
            'support_telegram' => null,
        ]);

        $response = $this->getJson('/api/fawateer/remote-config')->assertOk();

        $payload = $response->json();
        $support = $response->json('support');

        $this->assertIsArray($payload);
        $this->assertIsArray($support);

        $this->assertSame(
            ['latest_version', 'api', 'downloads', 'update_notes', 'support'],
            array_keys($payload),
        );
        $this->assertSame(['email', 'whatsapp', 'telegram'], array_keys($support));
    }

    /** A trailing slash would produce `…/api/fawateer//check_device`. */
    public function test_a_trailing_slash_on_the_base_url_is_trimmed(): void
    {
        $this->fawateer()->update(['api_base_url' => 'https://example.test/api/fawateer/']);

        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath('api.base_url', 'https://example.test/api/fawateer');
    }

    /**
     * `update_notes` must serialize as a JSON array. A map (which is what a PHP
     * array with gaps produces) is dropped wholesale by Fawateer's parser, which
     * only accepts a List.
     */
    public function test_update_notes_serialize_as_a_list(): void
    {
        $this->actAsStaff();
        $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
            'update_notes' => ['أول إصدار عام', 'تجربة مجانية 30 يوماً'],
        ])->assertOk();

        $notes = $this->getJson('/api/fawateer/remote-config')->assertOk()->json('update_notes');

        $this->assertSame(['أول إصدار عام', 'تجربة مجانية 30 يوماً'], $notes);
    }

    // --- Editing ---------------------------------------------------------------

    public function test_editing_the_config_changes_what_the_app_fetches(): void
    {
        $this->actAsStaff();

        $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
            'latest_version' => '1.1.0',
            'downloads' => ['arm64-v8a' => 'https://evotech-sys.com/f-1.1.0-arm64.apk'],
            'support_whatsapp' => '963900000000',
        ])->assertOk();

        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath('latest_version', '1.1.0')
            ->assertJsonPath('downloads.arm64-v8a', 'https://evotech-sys.com/f-1.1.0-arm64.apk')
            ->assertJsonPath('support.whatsapp', '963900000000');
    }

    public function test_the_remote_config_requires_authentication_to_edit(): void
    {
        $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
            'latest_version' => '9.9.9',
        ])->assertUnauthorized();

        $this->assertSame('1.0.0', $this->fawateer()->refresh()->latest_version);
    }

    // --- The parser landmines --------------------------------------------------

    /**
     * Versions are compared component-wise with `int.tryParse(part) ?? 0`, so the
     * "0-beta" component reads as 0 and 1.2.0-beta compares as 1.2.0 — an update
     * that can never be newer than itself, with no error anywhere.
     */
    public function test_a_non_numeric_version_is_rejected(): void
    {
        $this->actAsStaff();

        // Note a trailing space is absent from this list: TrimStrings strips it
        // before validation, so " 1.2.0 " is accepted and stored as "1.2.0" —
        // harmless, and worth not pretending otherwise.
        foreach (['1.2.0-beta', 'v1.2.0', 'latest', '1.2.0+build'] as $version) {
            $this->assertRejectedField(
                $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
                    'latest_version' => $version,
                ]),
                'latest_version',
            );
        }

        $this->assertSame('1.0.0', $this->fawateer()->refresh()->latest_version);
    }

    /**
     * Download keys are matched exactly against the ABI the device reports, so a
     * typo is not a cosmetic error — it is an update no device can ever find.
     */
    public function test_an_unrecognised_download_key_is_rejected(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField(
            $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
                'downloads' => ['arm64' => 'https://evotech-sys.com/f.apk'],
            ]),
            'downloads',
        );
    }

    public function test_known_download_keys_are_accepted(): void
    {
        $this->actAsStaff();

        $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
            'downloads' => [
                'arm64-v8a' => 'https://evotech-sys.com/a.apk',
                'armeabi-v7a' => 'https://evotech-sys.com/b.apk',
                'default' => 'https://evotech-sys.com/c.apk',
            ],
        ])->assertOk();

        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath('downloads.default', 'https://evotech-sys.com/c.apk');
    }

    public function test_a_download_url_must_be_a_url(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField(
            $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
                'downloads' => ['arm64-v8a' => 'not-a-url'],
            ]),
            'downloads.arm64-v8a',
        );
    }

    public function test_a_bad_base_url_is_rejected(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField(
            $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
                'api_base_url' => 'api.evotech-sys.com/api/fawateer',
            ]),
            'api_base_url',
        );
    }

    // --- Download links from the Download Center -------------------------------

    /**
     * Publishing a release fills in the download link with no config edit — the
     * step that turns "upload a build" into "users can install it".
     *
     * Reached through Core's locator port, so this module never touches the
     * Downloads models (§2.4). The port is faked here for the same reason: the
     * behaviour under test is the mapping, not the Download Center.
     */
    public function test_a_published_release_fills_in_the_download_link(): void
    {
        $this->app->instance(ReleaseDownloadLocator::class, new class implements ReleaseDownloadLocator
        {
            /** @return array<string, string> */
            public function latestDownloadUrls(string $productSlug, ?string $channel = null): array
            {
                return $productSlug === 'invoices'
                    ? ['android' => 'https://api.evotech-sys.com/api/v1/downloads/latest/invoices/android']
                    : [];
            }
        });

        $product = Product::factory()->create(['slug' => 'invoices']);
        $this->fawateer()->update(['product_id' => $product->id, 'downloads' => null]);

        /*
         * Keyed `default`, not an ABI: artifacts are unique on (release, platform),
         * so a release holds exactly one Android build and there is no per-ABI
         * split to derive. Fawateer falls back to the first non-empty value when no
         * ABI matches, so one universal APK reaches every device.
         */
        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath(
                'downloads.default',
                'https://api.evotech-sys.com/api/v1/downloads/latest/invoices/android',
            );
    }

    /**
     * A hand-set map wins outright rather than merging. Merging would produce a
     * map the operator never chose and cannot see — half hand-set, half derived —
     * and overriding what publishing produces is the field's entire purpose.
     */
    public function test_manual_links_override_the_published_release(): void
    {
        $this->app->instance(ReleaseDownloadLocator::class, new class implements ReleaseDownloadLocator
        {
            /** @return array<string, string> */
            public function latestDownloadUrls(string $productSlug, ?string $channel = null): array
            {
                return ['android' => 'https://example.test/derived.apk'];
            }
        });

        $product = Product::factory()->create(['slug' => 'invoices']);
        $this->fawateer()->update([
            'product_id' => $product->id,
            'downloads' => ['arm64-v8a' => 'https://example.test/manual.apk'],
        ]);

        $payload = $this->getJson('/api/fawateer/remote-config')->assertOk();

        $payload->assertJsonPath('downloads.arm64-v8a', 'https://example.test/manual.apk');
        $payload->assertJsonMissingPath('downloads.default');
    }

    /** An app with no product linked simply has nothing to offer. */
    public function test_an_unlinked_app_has_no_derived_links(): void
    {
        $this->fawateer()->update(['product_id' => null, 'downloads' => null]);

        $this->getJson('/api/fawateer/remote-config')
            ->assertOk()
            ->assertJsonPath('downloads', []);
    }

    // --- app-download, which used to disagree with this ------------------------

    /**
     * `latest_version` had two homes: this endpoint (env-backed, unset, so always
     * null) and the static config file. They now read the same column.
     */
    public function test_app_download_reads_the_same_source(): void
    {
        $this->actAsStaff();
        $this->patchJson("/api/v1/device-apps/{$this->fawateer()->uuid}", [
            'latest_version' => '2.0.0',
            'downloads' => ['arm64-v8a' => 'https://evotech-sys.com/f.apk'],
        ])->assertOk();

        $this->getJson('/api/fawateer/app-download')
            ->assertOk()
            ->assertJsonPath('latest_version', '2.0.0')
            ->assertJsonPath('downloads.arm64-v8a', 'https://evotech-sys.com/f.apk');
    }

    /** The un-namespaced surface cannot tell which app is asking. */
    public function test_app_download_without_a_slug_still_answers(): void
    {
        $this->getJson('/api/app-download')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
