<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A plain browser request (Accept: text/html) to a protected API route used to
 * 500 with "Route [login] not defined": the framework's default guest redirect
 * calls route('login') inside the auth middleware, before ApiExceptionRenderer
 * ever runs — and this API-only app has no login page. bootstrap/app.php now
 * overrides that redirect so the request falls through to the 401 envelope.
 */
class BrowserRequestAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway protected route, so this test does not depend on any
        // particular module's routing.
        Route::middleware('auth:sanctum')->get('/api/v1/_probe/protected', fn () => ['ok' => true]);
    }

    public function test_a_browser_request_to_a_protected_route_gets_a_json_401(): void
    {
        $this->get('/api/v1/_probe/protected')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_api_client_request_to_a_protected_route_gets_a_json_401(): void
    {
        $this->getJson('/api/v1/_probe/protected')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
