<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\CustomerController;

/*
 * Customers module API routes. Tenant-scoped: results are filtered to the
 * authenticated user's company via the BelongsToCompany global scope.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::apiResource('customers', CustomerController::class);
    });
