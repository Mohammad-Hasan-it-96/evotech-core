<?php

namespace Modules\DeviceSubscriptions\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\DeviceSubscriptions\Application\Listeners\SyncAppVersionFromRelease;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Modules\DeviceSubscriptions\Application\Services\SyncEnrollmentService;
use Modules\DeviceSubscriptions\Console\ImportLegacyDevicesCommand;
use Modules\DeviceSubscriptions\Console\PruneSyncChangesCommand;
use Modules\DeviceSubscriptions\Console\SweepDeviceExpiryCommand;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Contracts\SyncContext;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;
use Modules\DeviceSubscriptions\Infrastructure\Auth\RequestSyncContext;
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

        // The authenticated device seat's identity for the current request (ADR
        // 0011), exposed to controllers so the DeviceSeat model never leaks out of
        // this module — mirrors Gateway's ProductContext binding.
        $this->app->scoped(SyncContext::class, RequestSyncContext::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SweepDeviceExpiryCommand::class,
                ImportLegacyDevicesCommand::class,
                PruneSyncChangesCommand::class,
            ]);
        }

        // React to a Download Center publish by aligning the consumer app's
        // advertised update version — when the operator asked for it (§2.4).
        Event::listen(ReleasePublished::class, SyncAppVersionFromRelease::class);

        // The `device-sync` guard (config/auth.php) resolves a DeviceSeat from its
        // per-device sync token, read from Authorization: Bearer or X-Sync-Token.
        // This is where cross-business isolation begins: the resolved seat carries
        // the only business scope the request is ever trusted with (Decision 4).
        Auth::viaRequest('device-sync-token', function (Request $request): ?DeviceSeat {
            $token = $request->bearerToken() ?? $request->header('X-Sync-Token');

            if (! is_string($token) || $token === '') {
                return null;
            }

            return app(SyncEnrollmentService::class)->authenticate($token);
        });
    }
}
