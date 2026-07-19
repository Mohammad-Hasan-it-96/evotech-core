<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\Products\Domain\Models\Product;

/**
 * Builds the remote-config payload a shipped app fetches at startup.
 *
 * The shape is a contract with builds already on customers' phones and is
 * documented in docs/api/fawateer-device-contract.md §9. Their parsers are
 * defensive — a malformed field degrades to a default rather than throwing — which
 * means a mistake here does not surface as an error anywhere. It surfaces as an
 * update prompt that never fires, or support contacts that quietly revert.
 *
 * Two invariants exist because of how those parsers fail:
 *
 *  - **`api.base_url` is never empty.** Fawateer skips the assignment and keeps its
 *    compiled-in default; SmartAgent is worse and *resets* the persisted URL to the
 *    legacy `harrypotter.foodsalebot.com` host. So an app with nothing configured
 *    still gets a derived URL rather than a blank one.
 *  - **Every key is always present.** Emitting a partial object saves nothing and
 *    an absent key and an empty one take different paths through those parsers.
 */
final class DeviceRemoteConfigBuilder
{
    /*
     * Reached through Core's port, not the Downloads module (§2.4). Core binds a
     * no-op default, so this keeps working — with no download links — whether or
     * not the Download Center is present.
     */
    public function __construct(private readonly ReleaseDownloadLocator $locator) {}

    /**
     * @return array<string, mixed>
     */
    public function build(DeviceApp $app): array
    {
        return [
            // Compared component-wise as integers, so "" simply disables the update
            // check. Constrained to digits and dots at the request layer.
            'latest_version' => (string) ($app->latest_version ?? ''),

            'api' => [
                'base_url' => $this->baseUrl($app),
            ],

            /*
             * ABI => URL. Keys are matched exactly against the device's reported
             * ABI, so a typo is an update the device can never find.
             *
             * Cast to an object when empty so this serializes as `{}` rather than
             * PHP's `[]`. Both parsers read a JSON list as "not a map" and end up
             * with an empty map either way, so this is fidelity to the file it
             * replaces rather than a behaviour fix — but with parsers this quiet,
             * matching exactly is worth more than matching in effect.
             */
            'downloads' => $this->downloads($app) ?: (object) [],

            // Must be a list of strings: Fawateer's parser drops a bare string
            // outright, and an object would render as nothing.
            'update_notes' => array_values($this->strings($app->update_notes)),

            'support' => [
                'email' => (string) ($app->support_email ?? ''),
                'whatsapp' => (string) ($app->support_whatsapp ?? ''),
                'telegram' => (string) ($app->support_telegram ?? ''),
            ],
        ];
    }

    /**
     * The app's download links: whatever an operator set by hand, else the current
     * published build from the Download Center.
     *
     * Manual entries win outright rather than merging. Merging would produce a map
     * an operator never chose and cannot see — half hand-set, half derived — and
     * the whole point of the manual field is overriding what publishing produces.
     *
     * Android artifacts map onto the keys the apps actually look up: a build's
     * `variant` *is* the ABI key (`arm64-v8a`), and a universal build becomes
     * `default`, which is what both parsers fall back to when no ABI matches. Any
     * other platform is dropped — this config is read by Android apps, and a
     * `windows` key would be noise the parsers ignore.
     *
     * @return array<string, string>
     */
    private function downloads(DeviceApp $app): array
    {
        $manual = $this->strings($app->downloads);

        if ($manual !== []) {
            return $manual;
        }

        if ($app->product_id === null) {
            return [];
        }

        $slug = Product::query()->whereKey($app->product_id)->value('slug');

        if (! is_string($slug) || $slug === '') {
            return [];
        }

        $derived = [];

        foreach ($this->locator->latestDownloadUrls($slug) as $download) {
            if ($download['platform'] !== 'android' || $download['url'] === '') {
                continue;
            }

            $key = $download['variant'] === '' ? 'default' : $download['variant'];

            $derived[$key] = $download['url'];
        }

        return $derived;
    }

    /**
     * The app's explicit base URL, else one derived from its slug.
     *
     * Deriving is the fallback rather than the norm: it depends on `app.url` being
     * right on the server, and a wrong-but-non-empty URL is worse than a missing
     * one — the app accepts it and talks to the wrong host.
     */
    private function baseUrl(DeviceApp $app): string
    {
        $configured = $app->api_base_url;

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        $root = config('app.url');
        $root = is_string($root) ? rtrim($root, '/') : '';

        return "{$root}/api/{$app->slug}";
    }

    /**
     * Coerces a stored JSON column into a string map, dropping anything that would
     * serialize as a non-string (a nested object in `downloads` reaches the app as
     * the literal text "Instance of ..." once `.toString()` is applied to it).
     *
     * @return array<string, string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $entry) {
            if (is_string($entry) || is_int($entry) || is_float($entry)) {
                $strings[(string) $key] = trim((string) $entry);
            }
        }

        return array_filter($strings, static fn (string $entry): bool => $entry !== '');
    }
}
