<?php

namespace Modules\DeviceSubscriptions\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\DeviceSubscriptions\Application\Listeners\SyncAppVersionFromRelease;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Modules\DeviceSubscriptions\Console\ImportLegacyDevicesCommand;
use Modules\DeviceSubscriptions\Console\SweepDeviceExpiryCommand;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Infrastructure\Push\FirebasePushNotifier;
use Modules\DeviceSubscriptions\Infrastructure\Push\NullPushNotifier;
use Modules\Downloads\Domain\Events\ReleasePublished;

/**
 * DeviceSubscriptions module (ADR 0010): device-keyed, non-tenant subscriptions
 * for shipped consumer apps (the SmartAgent migration). Replicates the legacy
 * app_harfoshs contract on a compatibility shim while exposing versioned,
 * authenticated twins for future app releases.
 */
final class DeviceSubscriptionsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'DeviceSubscriptions';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('Config/device-subscriptions.php'), 'device-subscriptions');

        // Singleton so the per-request memo actually memoises: the catalog is read
        // several times while serving one device poll (trial terms, label, plans).
        $this->app->singleton(DeviceCatalogStore::class);

        // Safe default: a no-op notifier so the module never depends on Firebase
        // credentials to boot or test. Set DEVICE_PUSH_NOTIFIER=firebase in an
        // environment that has FCM configured.
        $this->app->bind(DevicePushNotifier::class, function (): DevicePushNotifier {
            return config('device-subscriptions.push_notifier') === 'firebase'
                ? $this->app->make(FirebasePushNotifier::class)
                : $this->app->make(NullPushNotifier::class);
        });
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SweepDeviceExpiryCommand::class,
                ImportLegacyDevicesCommand::class,
            ]);
        }

        // React to a Download Center publish by aligning the consumer app's
        // advertised update version — when the operator asked for it (§2.4).
        Event::listen(ReleasePublished::class, SyncAppVersionFromRelease::class);
    }
}
