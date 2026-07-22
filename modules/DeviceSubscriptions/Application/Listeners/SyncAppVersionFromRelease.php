<?php

namespace Modules\DeviceSubscriptions\Application\Listeners;

use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\Downloads\Domain\Events\ReleasePublished;
use Modules\Products\Domain\Models\Product;

/**
 * Aligns a consumer app's advertised update version to a release an operator just
 * published — but only when they opted in (ReleasePublished::syncAppVersion).
 *
 * The apps compare `latest_version` component-wise as integers, so a release
 * version carrying anything but digits and dots (a "v" prefix, a "-beta" suffix)
 * would read as 0 and hide the update. Such a version is skipped rather than
 * written: the build is still published, only the auto-announce is declined —
 * better than silently pinning the app to a version it can never reach.
 *
 * Reached through the Downloads event (§2.4): DeviceSubscriptions reacts to what
 * Downloads announced rather than Downloads writing a DeviceApp it does not own.
 */
final class SyncAppVersionFromRelease
{
    public function handle(ReleasePublished $event): void
    {
        if (! $event->syncAppVersion) {
            return;
        }

        // Must be the digits-and-dots the shipped parsers accept, and fit the
        // column the app config is served from.
        if (preg_match('/^\d+(\.\d+)*$/', $event->version) !== 1 || mb_strlen($event->version) > 20) {
            return;
        }

        $productId = Product::query()->where('slug', $event->productSlug)->value('id');

        if ($productId === null) {
            return;
        }

        DeviceApp::query()
            ->where('product_id', $productId)
            ->update(['latest_version' => $event->version]);
    }
}
