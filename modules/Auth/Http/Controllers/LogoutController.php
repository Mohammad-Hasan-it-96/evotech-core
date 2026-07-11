<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Application\Services\AuthService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Domain\Models\User;

final class LogoutController extends ApiController
{
    public function __invoke(Request $request, AuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $auth->logout($user);

        return $this->noContent();
    }
}
