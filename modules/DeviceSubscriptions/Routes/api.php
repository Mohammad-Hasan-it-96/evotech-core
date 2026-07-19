<?php

use Illuminate\Support\Facades\Route;
use Modules\DeviceSubscriptions\Http\Controllers\AppDownloadController;
use Modules\DeviceSubscriptions\Http\Controllers\DeviceAdminController;
use Modules\DeviceSubscriptions\Http\Controllers\DeviceCatalogController;
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

/*
 * The shim's endpoints, registered under two prefixes from one definition so the
 * surfaces cannot drift apart:
 *
 *  - /api/*        — the shared surface every shipped build points at today.
 *  - /api/{app}/*  — the same contract namespaced per app (Phase D). getPlans is
 *                    the only device endpoint with no app_name in the body, so the
 *                    slug is what lets one backend serve different plans per app.
 *                    An app moves onto it by editing its remote-config base URL:
 *                    no store release, and reverting is the same one-value edit.
 */
$legacyEndpoints = function (): void {
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
};

Route::prefix('api')->group($legacyEndpoints);

/*
 * `app` excludes any version segment (v1, and any future v2…). Nothing under
 * /api/v1 ends in one of the literal segments above, so no route is shadowed today
 * — but this group is registered before most modules' /api/v1 routes, and the guard
 * keeps a future `/api/v1/<one of those names>` from silently resolving here.
 *
 * Note the pattern is embedded mid-regex by the route compiler, so `$` would anchor
 * to the end of the whole URI rather than this segment: the exclusion has to be a
 * prefix rule, not an anchored one.
 */
Route::prefix('api/{app}')
    ->where(['app' => '(?!v\d)[a-z][a-z0-9_-]*'])
    ->group($legacyEndpoints);

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
        Route::get('device-subscriptions/plans', [DeviceAdminController::class, 'plansV1'])
            ->name('device-subscriptions.plans');
        Route::post('device-subscriptions/{deviceSubscription}/activate', [DeviceAdminController::class, 'activateV1'])
            ->name('device-subscriptions.activate');
        Route::post('device-subscriptions/{deviceSubscription}/decline', [DeviceAdminController::class, 'declineV1'])
            ->name('device-subscriptions.decline');
        Route::delete('device-subscriptions/{deviceSubscription}', [DeviceAdminController::class, 'destroyV1'])
            ->name('device-subscriptions.destroy');

        /*
         * Catalog editor. Note these sit alongside `device-subscriptions/plans`,
         * which is a different thing and stays: that one serves the legacy-shaped
         * list the activate dialog picks from, matching exactly what the device
         * sees. These are the admin views — disabled plans included, writable.
         */
        Route::get('device-apps', [DeviceCatalogController::class, 'apps'])
            ->name('device-apps.index');
        Route::patch('device-apps/{deviceApp}', [DeviceCatalogController::class, 'updateApp'])
            ->name('device-apps.update');

        Route::get('device-plans', [DeviceCatalogController::class, 'plans'])
            ->name('device-plans.index');
        Route::post('device-plans', [DeviceCatalogController::class, 'storePlan'])
            ->name('device-plans.store');
        Route::patch('device-plans/{devicePlan}', [DeviceCatalogController::class, 'updatePlan'])
            ->name('device-plans.update');
        Route::delete('device-plans/{devicePlan}', [DeviceCatalogController::class, 'destroyPlan'])
            ->name('device-plans.destroy');
    });
