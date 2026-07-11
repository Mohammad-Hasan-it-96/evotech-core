<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters (constitution §6.13). The "api" limiter backs the
     * "api" middleware group used by every module's API routes.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier() ?: $request->ip()
            );
        });

        // Stricter limiter for auth endpoints: per-account AND per-IP (§6.13).
        RateLimiter::for('auth', function (Request $request) {
            $email = $request->string('email')->toString();

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Product-to-platform edge: per-API-key limiter (§6.13). Keyed by the
        // authenticated product key, falling back to IP for unauthenticated hits.
        RateLimiter::for('product', function (Request $request) {
            $product = $request->user('product');
            $id = $product instanceof Authenticatable ? $product->getAuthIdentifier() : $request->ip();
            $key = is_scalar($id) ? (string) $id : 'unknown';

            return Limit::perMinute(120)->by('product|'.$key);
        });
    }
}
