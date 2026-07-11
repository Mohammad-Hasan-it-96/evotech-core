<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductController;

/*
 * Products module API routes. Public read-only catalog (no auth) — the single
 * source of product/plan data for the website and dashboard.
 */
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
});
