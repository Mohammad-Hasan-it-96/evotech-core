<?php

use Illuminate\Support\Facades\Route;
use Modules\Licenses\Http\Controllers\LicenseActivationController;
use Modules\Licenses\Http\Controllers\LicenseController;
use Modules\Licenses\Http\Controllers\Product\OfflineTokenController;
use Modules\Licenses\Http\Controllers\Product\ProductLicenseController;
use Modules\Licenses\Http\Controllers\Product\SigningKeyController;

/*
 * Licenses module API routes.
 *
 * Two audiences:
 *  - Admin/staff (auth:sanctum): the license lifecycle + activation management.
 *  - Products (auth:product, ADR 0004): self-activation + online validation,
 *    scoped to the authenticated product's own licenses.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::post('licenses/{license}/suspend', [LicenseController::class, 'suspend'])
            ->name('licenses.suspend');
        Route::post('licenses/{license}/reactivate', [LicenseController::class, 'reactivate'])
            ->name('licenses.reactivate');
        Route::post('licenses/{license}/revoke', [LicenseController::class, 'revoke'])
            ->name('licenses.revoke');

        // Device/domain activation slots for a license.
        Route::get('licenses/{license}/activations', [LicenseActivationController::class, 'index'])
            ->name('licenses.activations.index');
        Route::post('licenses/{license}/activations', [LicenseActivationController::class, 'store'])
            ->name('licenses.activations.store');
        Route::delete('licenses/{license}/activations/{activation}', [LicenseActivationController::class, 'destroy'])
            ->scopeBindings()
            ->name('licenses.activations.destroy');

        Route::get('licenses', [LicenseController::class, 'index'])->name('licenses.index');
        Route::post('licenses', [LicenseController::class, 'store'])->name('licenses.store');
        Route::get('licenses/{license}', [LicenseController::class, 'show'])->name('licenses.show');
    });

/*
 * Product-facing endpoints — authenticated by a per-product API key (ADR 0004)
 * and throttled per key. A product references its licenses by key and may only
 * act on licenses belonging to its own product.
 */
Route::prefix('api/v1/product')
    ->name('api.v1.product.')
    ->middleware(['auth:product', 'throttle:product'])
    ->group(function (): void {
        Route::post('licenses/activate', [ProductLicenseController::class, 'activate'])
            ->name('licenses.activate');
        Route::post('licenses/validate', [ProductLicenseController::class, 'validate'])
            ->name('licenses.validate');
        Route::post('licenses/deactivate', [ProductLicenseController::class, 'deactivate'])
            ->name('licenses.deactivate');

        // Signed offline token for an already-activated device (ADR 0005).
        Route::post('licenses/token', [OfflineTokenController::class, 'issue'])
            ->name('licenses.token');
    });

/*
 * Public verification key(s) for offline license tokens (ADR 0005). No auth — a
 * verification key is not a secret; throttled by the standard `api` limiter.
 * Devices fetch this while online and cache it to verify tokens offline.
 */
Route::prefix('api/v1/product')
    ->name('api.v1.product.')
    ->group(function (): void {
        Route::get('keys', SigningKeyController::class)->name('keys');
    });
