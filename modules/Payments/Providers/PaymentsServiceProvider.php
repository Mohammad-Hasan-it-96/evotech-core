<?php

namespace Modules\Payments\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Payments\Application\Listeners\IssueInvoiceOnActivation;
use Modules\Payments\Domain\Contracts\PaymentGateway;
use Modules\Payments\Domain\Contracts\PaymentStats;
use Modules\Payments\Infrastructure\Gateways\ManualPaymentGateway;
use Modules\Payments\Infrastructure\Gateways\StripePaymentGateway;
use Modules\Payments\Infrastructure\Reporting\EloquentPaymentStats;
use Modules\Payments\Infrastructure\Stripe\StripeClient;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Payments module: billing — invoices, payments, and the immutable payment ledger
 * (ADR 0006). Composition module — references Companies + Subscriptions and reacts
 * to Subscriptions events. The active gateway is chosen by `config('payments.gateway')`:
 * `manual`/offline by default, or the live `stripe` adapter (ADR 0009) — both behind
 * the same PaymentGateway contract.
 */
final class PaymentsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Payments';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('Config/payments.php'), 'payments');

        $this->app->bind(StripeClient::class, function (): StripeClient {
            $secret = config('payments.stripe.secret');
            $apiBase = config('payments.stripe.api_base');

            return new StripeClient(
                is_string($secret) ? $secret : '',
                is_string($apiBase) ? $apiBase : 'https://api.stripe.com',
            );
        });

        // The active gateway is selected at resolve time so config/env changes and
        // tests take effect without re-registering the container.
        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            return config('payments.gateway') === 'stripe'
                ? app(StripePaymentGateway::class)
                : app(ManualPaymentGateway::class);
        });

        $this->app->bind(PaymentStats::class, EloquentPaymentStats::class);
    }

    protected function bootModule(): void
    {
        Event::listen(SubscriptionActivated::class, IssueInvoiceOnActivation::class);
    }
}
