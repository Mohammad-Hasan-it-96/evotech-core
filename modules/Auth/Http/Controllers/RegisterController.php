<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Application\Services\AuthService;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Http\Resources\UserResource;

final class RegisterController extends ApiController
{
    public function __invoke(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        [$user, $token] = $auth->register($request->toData());

        return $this->created([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }
}
