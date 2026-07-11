<?php

namespace Modules\Auth\Application\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Application\DTOs\LoginData;
use Modules\Auth\Application\DTOs\RegisterData;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Users\Domain\Models\User;

/**
 * Authentication use-cases. Issues Sanctum personal access tokens for API clients
 * (mobile / desktop / products). SPA cookie auth is layered on in Phase 3.
 */
final class AuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{User, string} the user and a plain-text token
     */
    public function register(RegisterData $data): array
    {
        $user = User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password, // hashed by the model cast
        ]);

        $this->audit->log('auth.registered', 'user', $user->uuid, actorId: $user->uuid);

        return [$user, $this->issueToken($user, $data->deviceName)];
    }

    /**
     * @return array{User, string}
     *
     * @throws ValidationException on bad credentials
     */
    public function login(LoginData $data): array
    {
        $user = User::where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            $this->audit->log('auth.login_failed', context: ['email' => $data->email]);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $this->audit->log('auth.login', 'user', $user->uuid, actorId: $user->uuid);

        return [$user, $this->issueToken($user, $data->deviceName)];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        $this->audit->log('auth.logout', 'user', $user->uuid, actorId: $user->uuid);
    }

    private function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName)->plainTextToken;
    }
}
