<?php

namespace Modules\DeviceSubscriptions\Tests\Feature\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\DeviceSubscriptions\Application\DTO\EnrolledSeat;
use Modules\DeviceSubscriptions\Application\Services\SyncEnrollmentService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;

/**
 * Shared setup for the multi-device-sync feature tests (ADR 0011): standing up an
 * owner business, enrolling members, and forming the per-device auth header.
 */
trait InteractsWithSync
{
    private function enrollment(): SyncEnrollmentService
    {
        return app(SyncEnrollmentService::class);
    }

    /** A realistic 64-hex device id, so its first 16 chars form a stable node id. */
    private function deviceId(string $label): string
    {
        return hash('sha256', $label);
    }

    /**
     * The per-device sync auth header.
     *
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /*
     * The token-guard resolves a user once and memoises it on the AuthManager. A
     * real deployment gets a fresh process per request, but a test reuses one
     * application across several sub-requests — so a second request would reuse the
     * FIRST request's authenticated seat. These wrappers forget the guard before
     * each call so every sync request re-authenticates from its own Bearer token,
     * matching production. Non-sync calls (e.g. check_device) don't need them.
     */
    /** @return TestResponse<JsonResponse> */
    private function syncGet(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri, $this->bearer($token));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function syncPost(string $uri, array $body = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($uri, $body, $token !== null ? $this->bearer($token) : []);
    }

    /** @return TestResponse<JsonResponse> */
    private function syncDelete(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->deleteJson($uri, [], $this->bearer($token));
    }

    /**
     * Read a response JSON value that the tests need typed. `json()` is `mixed`,
     * and the suite runs under PHPStan level max with no PHPUnit narrowing
     * extension — so these keep the reads honest without unsafe casts.
     *
     * @param  TestResponse<JsonResponse>  $response
     */
    private function jsonString(TestResponse $response, string $key): string
    {
        $value = $response->json($key);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    private function jsonInt(TestResponse $response, string $key): int
    {
        $value = $response->json($key);

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return array<array-key, mixed>
     */
    private function jsonArray(TestResponse $response, string $key): array
    {
        $value = $response->json($key);

        return is_array($value) ? $value : [];
    }

    /** Stand up a business with its owner device, returning the owner's seat + token. */
    private function establishOwner(
        string $app = 'Fawateer',
        string $ownerLabel = 'owner',
        int $allowance = 3,
    ): EnrolledSeat {
        return $this->enrollment()->establishBusiness(
            appName: $app,
            deviceId: $this->deviceId($ownerLabel),
            deviceAllowance: $allowance,
            verified: true,
            expiresAt: Carbon::now()->addYear(),
        );
    }

    /** Mint a join token then redeem it for a new member device. */
    private function enrollMember(DeviceBusiness $business, string $memberLabel, ?string $pushToken = null): EnrolledSeat
    {
        $minted = $this->enrollment()->mintJoinToken($business->refresh());

        return $this->enrollment()->enroll($minted->plaintext, $this->deviceId($memberLabel), $pushToken);
    }
}
