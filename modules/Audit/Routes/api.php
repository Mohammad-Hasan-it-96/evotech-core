<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditLogController;

/*
 * Audit module API routes — staff read-only access to the audit trail
 * (auth:sanctum). The log is append-only; there are no write endpoints.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
