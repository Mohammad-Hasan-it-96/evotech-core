<?php

namespace Modules\Subscriptions\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Subscriptions\Console\ExpireSubscriptionsCommand;
use Modules\Subscriptions\Domain\Contracts\SubscriptionStats;
use Modules\Subscriptions\Infrastructure\Reporting\EloquentSubscriptionStats;

/**
 * Subscriptions module: links companies to plans and manages the subscription
 * lifecycle (create, renew, cancel, expire). Composes Companies + Products.
 */
final class SubscriptionsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Subscriptions';
    }

    public function register(): void
    {
        $this->app->bind(SubscriptionStats::class, EloquentSubscriptionStats::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireSubscriptionsCommand::class,
            ]);
        }
    }
}
