<?php

namespace Modules\DeviceSubscriptions\Domain\Contracts;

/**
 * Sends a push notification to a single device token. Abstracts the transport
 * (Firebase in production, a no-op locally/CI) so the module never hard-depends
 * on FCM credentials. The legacy backend's split send()/sendNotification() is
 * normalized to this one method (ADR 0010).
 */
interface DevicePushNotifier
{
    /**
     * @param  string  $token  the device FCM token
     * @param  string  $type  a machine key echoed to the client (e.g. "new_plan_activated")
     */
    public function send(string $token, string $title, string $body, string $type): void;
}
