<?php

use Illuminate\Support\Facades\Route;
use Modules\Downloads\Http\Controllers\ArtifactController;
use Modules\Downloads\Http\Controllers\DeliveryController;
use Modules\Downloads\Http\Controllers\DownloadEventController;
use Modules\Downloads\Http\Controllers\Product\ProductReleaseController;
use Modules\Downloads\Http\Controllers\ReleaseController;

/*
 * Downloads module API routes (ADR 0008).
 *
 * Three audiences:
 *  - Staff (auth:sanctum): manage releases + artifacts, mint links, read the ledger.
 *  - Delivery (signed): the one route that serves bytes, reachable only via a valid
 *    short-lived signed URL — no session/product auth.
 *  - Products (auth:product, ADR 0004): discover the latest release + self-update,
 *    scoped to the authenticated product's own artifacts.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::post('releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::get('releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
        Route::patch('releases/{release}', [ReleaseController::class, 'update'])->name('releases.update');
        Route::delete('releases/{release}', [ReleaseController::class, 'destroy'])->name('releases.destroy');
        Route::post('releases/{release}/publish', [ReleaseController::class, 'publish'])->name('releases.publish');
        Route::post('releases/{release}/archive', [ReleaseController::class, 'archive'])->name('releases.archive');

        // Per-platform artifacts of a release.
        Route::get('releases/{release}/artifacts', [ArtifactController::class, 'index'])->name('releases.artifacts.index');
        Route::post('releases/{release}/artifacts', [ArtifactController::class, 'store'])->name('releases.artifacts.store');
        Route::delete('artifacts/{artifact}', [ArtifactController::class, 'destroy'])->name('artifacts.destroy');
        Route::post('artifacts/{artifact}/link', [ArtifactController::class, 'link'])->name('artifacts.link');

        // Download ledger.
        Route::get('downloads/events', [DownloadEventController::class, 'index'])->name('downloads.events.index');
    });

/*
 * Signed delivery — the only route that serves artifact bytes. Protected by the
 * `signed` middleware; the link is minted by the staff/product link endpoints.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('signed')
    ->group(function (): void {
        Route::get('downloads/deliver/{artifact}', DeliveryController::class)->name('downloads.deliver');
    });

/*
 * Product-facing endpoints — authenticated by a per-product API key (ADR 0004)
 * and throttled per key. A product may only act on its own product's artifacts.
 */
Route::prefix('api/v1/product')
    ->name('api.v1.product.')
    ->middleware(['auth:product', 'throttle:product'])
    ->group(function (): void {
        Route::get('releases/latest', [ProductReleaseController::class, 'latest'])->name('releases.latest');
        Route::post('artifacts/{artifact}/link', [ProductReleaseController::class, 'link'])->name('artifacts.link');
    });
