<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportController;

/*
 * Reports module API routes — staff-only aggregations (auth:sanctum).
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('reports/overview', [ReportController::class, 'overview'])->name('reports.overview');
    });
