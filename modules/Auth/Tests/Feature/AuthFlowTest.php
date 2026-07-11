<?php

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password12345';

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Yasser',
            'email' => 'yasser@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'device_name' => 'test-device',
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']])
            ->assertJsonPath('data.user.email', 'yasser@example.com');

        $this->assertDatabaseHas('users', ['email' => 'yasser@example.com']);
    }

    public function test_registration_validation_returns_the_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'trace_id']])
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_password_must_meet_the_minimum_policy(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'test-device',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user' => ['id'], 'token']]);
    }

    public function test_login_with_wrong_password_fails_with_the_error_envelope(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'test-device',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_authenticated_user_can_fetch_profile_then_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->uuid);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }
}
