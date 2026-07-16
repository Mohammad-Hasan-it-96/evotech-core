<?php

namespace Modules\DeviceSubscriptions\Infrastructure\Push;

use Illuminate\Support\Facades\Log;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;

/**
 * Safe default notifier: records the intent to the log and sends nothing. Keeps
 * the module runnable with no Firebase configuration (local, CI, tests).
 */
final class NullPushNotifier implements DevicePushNotifier
{
    public function send(string $token, string $title, string $body, string $type): void
    {
        Log::debug('DeviceSubscriptions push suppressed (null notifier).', [
            'type' => $type,
            'title' => $title,
        ]);
    }
}
