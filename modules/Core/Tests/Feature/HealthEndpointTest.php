<?php

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_the_standard_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonStructure(['data' => ['status', 'service', 'api_version', 'environment']])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'evotech-core')
            ->assertJsonPath('data.api_version', 'v1');
    }

    public function test_api_routes_are_rate_limited(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 60);
    }

    public function test_unknown_api_route_returns_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound();
    }
}
