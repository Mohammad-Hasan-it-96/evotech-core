<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Http\Resources\UserResource;

final class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()));
    }
}
