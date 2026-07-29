<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\DeviceSubscriptions\Application\DTO\BootstrapHandoff;
use Modules\DeviceSubscriptions\Application\DTO\EnrolledSeat;
use Modules\DeviceSubscriptions\Application\DTO\MintedJoinToken;
use Modules\DeviceSubscriptions\Application\Support\SyncTokenGenerator;
use Modules\DeviceSubscriptions\Domain\Exceptions\SyncException;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Domain\Models\DeviceJoinToken;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Enrollment and seat life-cycle for multi-device sync (ADR 0011, Decisions 3, 5, 13).
 *
 * Owns three flows: an owner device establishing a business (the anchor seat),
 * an owner minting a single-use join token (with an optional bootstrap snapshot),
 * and a joining device redeeming that token for its own durable sync credential.
 * Every seat is scoped to exactly one business — cross-business isolation is the
 * feature's paramount security property, so identity is always derived from the
 * server-side seat/business, never from a request body.
 */
final class SyncEnrollmentService
{
    public function __construct(private readonly SyncTokenGenerator $tokens) {}

    /**
     * Resolve the live seat a sync token authenticates as, or null (the
     * `device-sync` guard resolver). Lookup is by hash — a wrong or revoked token
     * simply misses. Refreshes `last_used_at` on success.
     */
    public function authenticate(string $token): ?DeviceSeat
    {
        $seat = DeviceSeat::query()
            ->where('token_hash', $this->tokens->hash($token))
            ->first();

        if ($seat === null || ! $seat->isActive()) {
            return null;
        }

        $seat->forceFill(['last_used_at' => Carbon::now()])->save();

        return $seat;
    }

    /**
     * Stand up a new business and its OWNER seat in one step — the entry point
     * for the first device of a shop. The owner seat is role=owner (never
     * revocable, R1) and starts on an empty change log, so its bootstrap is
     * cursor 0 with no snapshot.
     *
     * The `app_name` and `device_allowance` are set server-side; `verified` and
     * `expires_at` mirror the subscription that authorised this business.
     */
    public function establishBusiness(
        string $appName,
        string $deviceId,
        int $deviceAllowance,
        bool $verified = false,
        ?Carbon $expiresAt = null,
        ?string $planId = null,
        ?string $pushToken = null,
    ): EnrolledSeat {
        if ($deviceId === DeviceSubscription::FALLBACK_DEVICE_ID) {
            throw SyncException::fallbackDeviceRejected();
        }

        return DB::transaction(function () use ($appName, $deviceId, $deviceAllowance, $verified, $expiresAt, $planId, $pushToken): EnrolledSeat {
            $business = DeviceBusiness::query()->create([
                'app_name' => $appName,
                'is_verified' => $verified,
                'expires_at' => $expiresAt,
                'plan_id' => $planId,
                'device_allowance' => $deviceAllowance,
                'last_seq' => 0,
            ]);

            $generated = $this->tokens->generateSeatToken();

            $seat = DeviceSeat::query()->create([
                'device_business_id' => $business->id,
                'app_name' => $appName,
                'device_id' => $deviceId,
                'node_id' => DeviceSeat::nodeIdFor($deviceId),
                'role' => DeviceSeat::ROLE_OWNER,
                'prefix' => $generated->prefix,
                'token_hash' => $generated->hash,
                'push_token' => $pushToken,
                'last_used_at' => Carbon::now(),
            ]);

            return new EnrolledSeat($seat, $generated->plaintext, new BootstrapHandoff(0));
        });
    }

    /**
     * Mint a single-use, short-TTL join token for a business (owner action). The
     * plaintext is returned once for the owner to render as a QR; only its hash is
     * stored. A bootstrap snapshot may be attached afterwards via {@see attachBootstrap()}.
     */
    public function mintJoinToken(DeviceBusiness $business): MintedJoinToken
    {
        $generated = $this->tokens->generateJoinToken();

        $ttlMinutes = Config::integer('device-subscriptions.sync.join_token_ttl_minutes');

        $token = DeviceJoinToken::query()->create([
            'device_business_id' => $business->id,
            'token_hash' => $generated->hash,
            'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
        ]);

        return new MintedJoinToken($token, $generated->plaintext);
    }

    /**
     * Record the bootstrap handoff an owner uploaded against a join token
     * (Decision 13): the pull cursor `C` (the owner's OWN local cursor, read
     * before its VACUUM), the transient snapshot path, and the owner-computed
     * SHA-256. The snapshot bytes themselves live on the private disk; this only
     * records where and what.
     */
    public function attachBootstrap(DeviceJoinToken $token, int $cursor, string $snapshotPath, string $sha256): DeviceJoinToken
    {
        $token->forceFill([
            'bootstrap_cursor' => $cursor,
            'snapshot_path' => $snapshotPath,
            'snapshot_sha256' => $sha256,
        ])->save();

        return $token;
    }

    /**
     * Redeem a join token: the joining device obtains its durable per-device sync
     * credential and the seed to catch up with the shop.
     *
     * Enforces, in order: a stable device id (Decision 5 rejects the legacy shared
     * fallback), a usable token, and the seat allowance (Decision 3 — counted at
     * the business level, revoked seats freeing their slot). A device re-enrolling
     * under the same id reuses its slot and rotates its credential rather than
     * consuming a second seat. The seat's `app_name` is taken from the business,
     * never from the joining device.
     */
    public function enroll(string $joinTokenPlaintext, string $deviceId, ?string $pushToken = null): EnrolledSeat
    {
        if ($deviceId === DeviceSubscription::FALLBACK_DEVICE_ID) {
            throw SyncException::fallbackDeviceRejected();
        }

        $token = $this->findUsableJoinToken($joinTokenPlaintext);

        if ($token === null) {
            throw SyncException::invalidJoinToken();
        }

        return DB::transaction(function () use ($token, $deviceId, $pushToken): EnrolledSeat {
            $business = DeviceBusiness::query()
                ->whereKey($token->device_business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $business->seats()->where('device_id', $deviceId)->first();

            // A brand-new device (or a previously revoked one being re-admitted)
            // must fit under the allowance; a device already holding a live seat
            // just rotates its credential and keeps its slot.
            if (! ($existing !== null && $existing->isActive()) && ! $business->hasSeatAvailable()) {
                throw SyncException::allowanceExceeded();
            }

            $generated = $this->tokens->generateSeatToken();

            $seat = $existing ?? new DeviceSeat;
            $seat->forceFill([
                'device_business_id' => $business->id,
                'app_name' => $business->app_name,
                'device_id' => $deviceId,
                'node_id' => DeviceSeat::nodeIdFor($deviceId),
                'role' => DeviceSeat::ROLE_MEMBER,
                'prefix' => $generated->prefix,
                'token_hash' => $generated->hash,
                'push_token' => $pushToken,
                'last_used_at' => Carbon::now(),
                'revoked_at' => null,
            ])->save();

            $token->forceFill(['consumed_at' => Carbon::now()])->save();

            return new EnrolledSeat($seat, $generated->plaintext, $this->handoffFor($token));
        });
    }

    /**
     * Revoke a member seat (owner action). The owner seat is the business anchor
     * and is refused (R1). Idempotent. Revocation is not a remote wipe (R2) — it
     * only stops the credential from authenticating and frees its allowance slot.
     */
    public function revokeSeat(DeviceSeat $seat): DeviceSeat
    {
        if ($seat->isOwner()) {
            throw SyncException::ownerSeatNonRevocable();
        }

        if ($seat->revoked_at === null) {
            $seat->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return $seat;
    }

    /**
     * A business's seats, newest first. Bounded set (a shop holds a handful).
     *
     * @return Collection<int, DeviceSeat>
     */
    public function seatsFor(DeviceBusiness $business): Collection
    {
        return $business->seats()->latest()->get();
    }

    private function findUsableJoinToken(string $plaintext): ?DeviceJoinToken
    {
        $token = DeviceJoinToken::query()
            ->where('token_hash', $this->tokens->hash($plaintext))
            ->first();

        return $token !== null && $token->isUsable() ? $token : null;
    }

    private function handoffFor(DeviceJoinToken $token): BootstrapHandoff
    {
        $cursor = $token->bootstrap_cursor ?? 0;

        if ($token->snapshot_path === null || $token->snapshot_sha256 === null) {
            return new BootstrapHandoff($cursor);
        }

        $ttlMinutes = Config::integer('device-subscriptions.sync.snapshot_url_ttl_minutes');

        $url = URL::temporarySignedRoute(
            'api.v1.sync.bootstrap',
            Carbon::now()->addMinutes($ttlMinutes),
            ['joinToken' => $token->uuid],
        );

        return new BootstrapHandoff($cursor, $url, $token->snapshot_sha256);
    }
}
