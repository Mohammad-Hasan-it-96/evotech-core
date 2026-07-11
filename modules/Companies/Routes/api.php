<?php

use Illuminate\Support\Facades\Route;
use Modules\Companies\Http\Controllers\CompanyController;

/*
 * Companies module API routes (platform-admin managed; not tenant-scoped).
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::apiResource('companies', CompanyController::class);
    });
