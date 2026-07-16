<?php

use Illuminate\Support\Facades\Route;
use Modules\DeviceSubscriptions\Http\Controllers\AppDownloadController;
use Modules\DeviceSubscriptions\Http\Controllers\DeviceAdminController;
use Modules\DeviceSubscriptions\Http\Controllers\DeviceController;
use Modules\DeviceSubscriptions\Http\Controllers\PlanController;

/*
 * DeviceSubscriptions routes (ADR 0010).
 *
 * Three groups:
 *  1. Legacy compatibility shim — unversioned /api/*, exact legacy contract, so the
 *     already-shipped app works the moment the Drive-JSON base URL is repointed.
 *     Device self-service is public (the app sends no token); the two admin
 *     endpoints are moved behind auth:sanctum (the ADR 0010 security fix). This
 *     group is the documented, time-boxed exception to §7's /api/v1 rule and is
 *     retired once a new app version adopts the versioned API below.
 *  2. Versioned device API — /api/v1/device/*, auth:product (ADR 0004), for the
 *     next app release. Same controllers, same response shapes, just authenticated.
 *  3. Versioned staff API — /api/v1/device-subscriptions, auth:sanctum, platform
 *     envelope, for the dashboard.
 */

// 1. Legacy shim ------------------------------------------------------------------
Route::prefix('api')->group(function (): void {
    // Public device self-service (shipped app, no auth).
    Route::post('create_device', [DeviceController::class, 'createDevice']);
    Route::post('check_device', [DeviceController::class, 'checkDevice']);
    Route::post('update_my_data', [DeviceController::class, 'updateMyData']);
    Route::post('add_review', [DeviceController::class, 'addReview']);
    Route::get('getPlans', [PlanController::class, 'index']);
    Route::get('app-download', [AppDownloadController::class, 'index']);

    // Admin — public in legacy, now staff-only (activateDevice/getDevice).
    // test_send_notifications is intentionally NOT ported (see the sweep command).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('activateDevice', [DeviceAdminController::class, 'activate']);
        Route::get('getDevice', [DeviceAdminController::class, 'index']);
    });
});

// 2. Versioned device API (auth:product) ------------------------------------------
Route::prefix('api/v1/device')
    ->name('api.v1.device.')
    ->middleware(['auth:product', 'throttle:product'])
    ->group(function (): void {
        Route::post('register', [DeviceController::class, 'createDevice'])->name('register');
        Route::post('check', [DeviceController::class, 'checkDevice'])->name('check');
        Route::post('profile', [DeviceController::class, 'updateMyData'])->name('profile');
        Route::post('review', [DeviceController::class, 'addReview'])->name('review');
        Route::get('plans', [PlanController::class, 'index'])->name('plans');
    });

// 3. Versioned staff API (auth:sanctum) -------------------------------------------
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('device-subscriptions', [DeviceAdminController::class, 'indexV1'])
            ->name('device-subscriptions.index');
        Route::post('device-subscriptions/{deviceSubscription}/activate', [DeviceAdminController::class, 'activateV1'])
            ->name('device-subscriptions.activate');
    });
