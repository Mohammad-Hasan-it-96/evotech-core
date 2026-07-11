<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\HealthController;

/*
 * Core module API routes. Registered by BaseModuleServiceProvider under the
 * "api" middleware group. All platform APIs are versioned under /api/v1 (§7).
 */
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});
