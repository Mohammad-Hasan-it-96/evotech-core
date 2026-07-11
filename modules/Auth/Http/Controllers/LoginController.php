<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Application\Services\AuthService;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Http\Resources\UserResource;

final class LoginController extends ApiController
{
    public function __invoke(LoginRequest $request, AuthService $auth): JsonResponse
    {
        [$user, $token] = $auth->login($request->toData());

        return $this->ok([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }
}
