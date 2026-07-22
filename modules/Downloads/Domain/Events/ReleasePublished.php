<?php

namespace Modules\Downloads\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a release is published. Carries only the facts other modules need
 * (product slug + version), so nothing has to load a Downloads model to react
 * (§2.4).
 *
 * `syncAppVersion` is the operator's opt-in, made at publish time, to align a
 * consumer app's advertised update version (DeviceSubscriptions) to this release.
 * Publishing a build and announcing it as the version to update *to* are separate
 * acts — the download links auto-track the latest publish, but the version number
 * the app compares does not — and this is where an operator ties them together for
 * a single publish.
 */
final class ReleasePublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $productSlug,
        public readonly string $version,
        public readonly bool $syncAppVersion = false,
    ) {}
}
