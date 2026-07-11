<?php

namespace Modules\Gateway\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Gateway\Domain\Contracts\ProductContext;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Gateway\Infrastructure\Auth\RequestProductContext;

/**
 * Gateway module: the product-to-platform edge. Owns per-product API keys and the
 * `product` request guard (ADR 0004), and publishes the {@see ProductContext}
 * contract so other modules read the authenticated product without touching the
 * Gateway's models directly (§2.1).
 */
final class GatewayServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Gateway';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('Config/gateway.php'), 'gateway');

        $this->app->scoped(ProductContext::class, RequestProductContext::class);
    }

    protected function bootModule(): void
    {
        // The `product` guard (config/auth.php) resolves a product from its API key,
        // read from the Authorization: Bearer header or X-Api-Key.
        Auth::viaRequest('product-api-key', function (Request $request): ?ProductApiKey {
            $token = $request->bearerToken() ?? $request->header('X-Api-Key');

            if (! is_string($token) || $token === '') {
                return null;
            }

            return app(ProductApiKeyService::class)->authenticate($token);
        });
    }
}
