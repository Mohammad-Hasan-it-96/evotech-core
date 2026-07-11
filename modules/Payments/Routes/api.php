<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\InvoiceController;
use Modules\Payments\Http\Controllers\PaymentController;
use Modules\Payments\Http\Controllers\StripePaymentController;
use Modules\Payments\Http\Controllers\StripeWebhookController;

/*
 * Payments module API routes — staff-managed billing (auth:sanctum). Invoices are
 * auto-issued on subscription activation; these endpoints list/issue/settle/void.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');

        // Start a Stripe card payment for an invoice (ADR 0009); returns a client secret.
        Route::post('invoices/{invoice}/payment-intent', [StripePaymentController::class, 'createIntent'])
            ->name('invoices.payment-intent');
    });

/*
 * Stripe webhook (ADR 0009) — unauthenticated by design; trust is established by
 * the HMAC signature verified inside the controller, not by a session/token.
 */
Route::prefix('api/v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
    });
