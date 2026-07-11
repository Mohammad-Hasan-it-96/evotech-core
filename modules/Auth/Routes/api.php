<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\LoginController;
use Modules\Auth\Http\Controllers\LogoutController;
use Modules\Auth\Http\Controllers\MeController;
use Modules\Auth\Http\Controllers\RegisterController;

/*
 * Auth module API routes. Registered under the "api" middleware group.
 * Public register/login are additionally throttled per-account + per-IP (§6.13).
 */
Route::prefix('api/v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:auth')
        ->name('register');

    Route::post('login', LoginController::class)
        ->middleware('throttle:auth')
        ->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::get('me', MeController::class)->name('me');
    });
});
