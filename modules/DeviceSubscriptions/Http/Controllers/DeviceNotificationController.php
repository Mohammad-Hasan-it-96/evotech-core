<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\DeviceSubscriptions\Application\Services\DeviceBroadcastService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceNotification;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Http\Requests\BroadcastNotificationRequest;
use Modules\DeviceSubscriptions\Http\Requests\SendTestNotificationRequest;
use Modules\DeviceSubscriptions\Http\Resources\DeviceNotificationResource;
use Modules\Users\Domain\Models\User;

/**
 * Custom device notifications — offers, updates, announcements (auth:sanctum).
 *
 * Deliberately two-step: `test` sends to one device (the operator's own phone) so
 * a message is seen on a real screen before `broadcast` sends it to an audience.
 * `index` is the history of everything sent.
 */
final class DeviceNotificationController extends ApiController
{
    public function __construct(private readonly DeviceBroadcastService $broadcast) {}

    public function index(): AnonymousResourceCollection
    {
        return DeviceNotificationResource::collection(
            DeviceNotification::query()->latest()->paginate(20),
        );
    }

    public function test(SendTestNotificationRequest $request): JsonResponse
    {
        $device = DeviceSubscription::query()
            ->where('uuid', $request->string('device')->value())
            ->firstOrFail();

        /** @var User|null $user */
        $user = $request->user();

        $notification = $this->broadcast->sendTest(
            $device,
            $request->string('title')->value(),
            $request->string('body')->value(),
            $user?->uuid,
            $user?->name,
        );

        return $this->created(DeviceNotificationResource::make($notification));
    }

    public function broadcast(BroadcastNotificationRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $notification = $this->broadcast->broadcast(
            $request->string('app')->value(),
            $request->boolean('active_only'),
            $request->string('title')->value(),
            $request->string('body')->value(),
            $user?->uuid,
            $user?->name,
        );

        return $this->created(DeviceNotificationResource::make($notification));
    }
}
