<?php

namespace Modules\Notifications\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Users\Domain\Models\User;

/**
 * The authenticated user's in-app notifications (the dashboard "bell"). A user
 * only ever sees and mutates their own notifications.
 */
final class NotificationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return NotificationResource::collection(
            $this->user($request)->notifications()->paginate($perPage)
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok([
            'unread' => $this->user($request)->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $this->user($request)->notifications()->findOrFail($notification)->markAsRead();

        return $this->noContent();
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->user($request)->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return $this->noContent();
    }

    /** The authenticated user (auth:sanctum guarantees one; narrowed for the Notifiable API). */
    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
