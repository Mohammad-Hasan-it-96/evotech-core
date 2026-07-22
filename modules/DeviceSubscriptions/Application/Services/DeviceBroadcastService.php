<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Validation\ValidationException;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceNotification;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Ad-hoc ("custom") device notifications — offers, updates, announcements — that
 * are not tied to subscription state (ADR 0010).
 *
 * The workflow the console is built around: compose once, send a **test** to one
 * device (the operator's own phone), eyeball it on a real screen, then
 * **broadcast** to an app's audience. Every send is recorded to
 * `device_notifications` for the history.
 *
 * These are FCM `notification` messages, so Android displays them from the tray
 * without the app parsing anything — an offer reaches a backgrounded app with no
 * client release. `type` is a single machine key (`custom_message`) so a
 * foregrounded app can route them generically rather than needing a contract
 * change per offer.
 */
final class DeviceBroadcastService
{
    public function __construct(
        private readonly DevicePushNotifier $push,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Send to a single device — the dry run before a broadcast.
     *
     * @throws ValidationException when the device has no push token to send to
     */
    public function sendTest(
        DeviceSubscription $device,
        string $title,
        string $body,
        ?string $actorId = null,
        ?string $actorName = null,
    ): DeviceNotification {
        if ($device->fcm_token === null || $device->fcm_token === '') {
            throw ValidationException::withMessages([
                'device' => 'This device has no push token, so nothing can be sent to it. Pick a device that has opened the app recently.',
            ]);
        }

        $this->push->send(
            (string) $device->app_name,
            $device->fcm_token,
            $title,
            $body,
            DeviceNotification::TYPE_CUSTOM,
        );

        return $this->record(
            appName: (string) $device->app_name,
            scope: DeviceNotification::SCOPE_TEST,
            activeOnly: false,
            title: $title,
            body: $body,
            recipients: 1,
            targetDeviceId: $device->device_id,
            actorId: $actorId,
            actorName: $actorName,
        );
    }

    /**
     * Broadcast to every device of an app that carries a push token, optionally
     * narrowed to active subscribers. Returns the recorded history row, whose
     * `recipients` is the number of devices dispatched to.
     */
    public function broadcast(
        string $appName,
        bool $activeOnly,
        string $title,
        string $body,
        ?string $actorId = null,
        ?string $actorName = null,
    ): DeviceNotification {
        $sent = 0;

        DeviceSubscription::query()
            ->where('app_name', $appName)
            ->whereNotNull('fcm_token')
            ->when($activeOnly, fn ($query) => $query->active())
            ->each(function (DeviceSubscription $device) use ($appName, $title, $body, &$sent): void {
                $this->push->send(
                    $appName,
                    (string) $device->fcm_token,
                    $title,
                    $body,
                    DeviceNotification::TYPE_CUSTOM,
                );
                $sent++;
            });

        return $this->record(
            appName: $appName,
            scope: DeviceNotification::SCOPE_BROADCAST,
            activeOnly: $activeOnly,
            title: $title,
            body: $body,
            recipients: $sent,
            targetDeviceId: null,
            actorId: $actorId,
            actorName: $actorName,
        );
    }

    private function record(
        string $appName,
        string $scope,
        bool $activeOnly,
        string $title,
        string $body,
        int $recipients,
        ?string $targetDeviceId,
        ?string $actorId,
        ?string $actorName,
    ): DeviceNotification {
        $notification = DeviceNotification::create([
            'app_name' => $appName,
            'scope' => $scope,
            'active_only' => $activeOnly,
            'title' => $title,
            'body' => $body,
            'type' => DeviceNotification::TYPE_CUSTOM,
            'recipients' => $recipients,
            'target_device_id' => $targetDeviceId,
            'sent_by' => $actorId,
            'sent_by_name' => $actorName,
        ]);

        $this->audit->log(
            'device_notification.'.$scope,
            'device_notification',
            $notification->uuid,
            [
                'app_name' => $appName,
                'recipients' => $recipients,
                'active_only' => $activeOnly,
                'title' => $title,
            ],
            $actorId,
        );

        return $notification;
    }
}
