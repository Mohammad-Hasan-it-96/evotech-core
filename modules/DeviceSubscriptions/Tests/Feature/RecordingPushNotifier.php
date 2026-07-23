<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;

/**
 * Stands in for FCM so tests pin *who* would be pushed to and *what* they would
 * read, not Google's transport. Own file (rather than a helper inside one test
 * class) so every push-asserting test autoloads the same recorder.
 */
final class RecordingPushNotifier implements DevicePushNotifier
{
    /** @var list<array{app: string, token: string, title: string, body: string, type: string}> */
    public array $sent = [];

    public function send(string $appName, string $token, string $title, string $body, string $type): void
    {
        $this->sent[] = [
            'app' => $appName,
            'token' => $token,
            'title' => $title,
            'body' => $body,
            'type' => $type,
        ];
    }
}
