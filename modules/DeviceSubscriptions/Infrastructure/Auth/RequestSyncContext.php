<?php

namespace Modules\DeviceSubscriptions\Infrastructure\Auth;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\DeviceSubscriptions\Domain\Contracts\SyncContext;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;

/**
 * Request-scoped {@see SyncContext} backed by the `device-sync` guard. Reads the
 * authenticated {@see DeviceSeat} and exposes only its identity — the concrete
 * seat model never leaves this module, exactly as Gateway's RequestProductContext
 * hides ProductApiKey.
 */
final class RequestSyncContext implements SyncContext
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function isAuthenticated(): bool
    {
        return $this->seat() !== null;
    }

    public function businessId(): ?int
    {
        return $this->seat()?->device_business_id;
    }

    public function seatId(): ?int
    {
        return $this->seat()?->id;
    }

    public function nodeId(): ?string
    {
        return $this->seat()?->node_id;
    }

    public function deviceId(): ?string
    {
        return $this->seat()?->device_id;
    }

    public function appName(): ?string
    {
        return $this->seat()?->app_name;
    }

    public function isOwner(): bool
    {
        return $this->seat()?->isOwner() ?? false;
    }

    private function seat(): ?DeviceSeat
    {
        $user = $this->auth->guard('device-sync')->user();

        return $user instanceof DeviceSeat ? $user : null;
    }
}
