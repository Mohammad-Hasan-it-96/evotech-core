<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unauthenticated API requests must answer 401 in the platform envelope (§7)
 * regardless of what the caller sends in `Accept`.
 *
 * They did not. `Authenticate::redirectTo()` calls `route('login')` eagerly, before
 * the AuthenticationException is constructed; this repo is API-only and has no such
 * route, so that call threw RouteNotFoundException, which reached the renderer as an
 * unknown error and became a 500.
 *
 * The tests below deliberately use `get`/`post` rather than `getJson`/`postJson`.
 * The JSON helpers set `Accept: application/json`, which is the one case that always
 * worked — so a suite built only from them cannot see this bug at all, which is
 * exactly how it survived.
 */
class UnauthenticatedApiResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function staffEndpoints(): array
    {
        return [
            'companies' => ['/api/v1/companies'],
            'subscriptions' => ['/api/v1/subscriptions'],
            'device subscriptions' => ['/api/v1/device-subscriptions'],
            'device plans' => ['/api/v1/device-plans'],
            'device apps' => ['/api/v1/device-apps'],
            'releases' => ['/api/v1/releases'],
        ];
    }

    #[DataProvider('staffEndpoints')]
    public function test_a_browser_request_gets_401_not_500(string $path): void
    {
        // No Accept header at all — how a browser address bar or a bare curl asks.
        $this->get($path)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[DataProvider('staffEndpoints')]
    public function test_an_html_request_gets_401_not_500(string $path): void
    {
        $this->get($path, ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /** The case that already worked, pinned so the fix does not regress it. */
    public function test_a_json_request_still_gets_401(): void
    {
        $this->getJson('/api/v1/companies')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * The product guard (ADR 0004) runs through the same middleware, so it had the
     * same failure — and product callers are machines that may well not send an
     * Accept header.
     */
    public function test_the_product_guard_also_answers_401(): void
    {
        $this->get('/api/v1/product/releases/latest')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->get('/api/v1/device/plans')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /** Public endpoints are unaffected — this changed guest handling, not routing. */
    public function test_public_endpoints_are_untouched(): void
    {
        $this->get('/api/getPlans')->assertOk();
        $this->get('/api/v1/products')->assertOk();
        $this->get('/up')->assertOk();
    }
}
