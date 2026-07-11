<?php

use Illuminate\Support\Facades\Route;
use Modules\Gateway\Http\Controllers\ProductApiKeyController;

/*
 * Gateway module API routes — staff management of product API keys (auth:sanctum).
 * The keys themselves authenticate products on the `product` guard, used by the
 * product-facing endpoints in other modules (e.g. Licenses).
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('products/{product}/api-keys', [ProductApiKeyController::class, 'index'])
            ->name('products.api-keys.index');
        Route::post('products/{product}/api-keys', [ProductApiKeyController::class, 'store'])
            ->name('products.api-keys.store');
        Route::delete('product-api-keys/{apiKey}', [ProductApiKeyController::class, 'destroy'])
            ->name('product-api-keys.destroy');
    });
