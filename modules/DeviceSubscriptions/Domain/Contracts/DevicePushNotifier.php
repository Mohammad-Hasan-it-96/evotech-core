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
     * @param  string  $appName  which app to send as — each has its OWN Firebase
     *                           project, so the credential is not interchangeable
     *                           and the sender cannot infer it from the token
     * @param  string  $token  the device FCM token
     * @param  string  $type  a machine key echoed to the client (e.g. "new_plan_activated")
     */
    public function send(string $appName, string $token, string $title, string $body, string $type): void;

    /**
     * A DATA-ONLY push — the multi-device-sync "doorbell" (ADR 0011). Carries no
     * `notification` block, so it never renders a tray alert; it only wakes the
     * app to pull. Delivery is best-effort convenience: a device that misses it
     * still converges on its next poll, so a lost doorbell only adds latency.
     *
     * @param  string  $appName  which app to send as (per-app Firebase project)
     * @param  string  $token  the device FCM token
     * @param  string  $type  the data key the app switches on (e.g. "sync")
     */
    public function sendData(string $appName, string $token, string $type): void;
}
