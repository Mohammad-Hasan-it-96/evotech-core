<?php

namespace Modules\Licenses\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Licenses\Application\Support\LicenseKeyGenerator;
use Modules\Licenses\Domain\Enums\LicenseEventType;
use Modules\Licenses\Domain\Enums\LicenseStatus;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Licenses\Domain\Models\LicenseEvent;
use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * License lifecycle use-cases: issuance (derived from a subscription), admin
 * transitions (suspend/reactivate/revoke), and the time-based expiry sweep.
 * Every state change appends to the immutable `license_events` ledger.
 */
final class LicenseService
{
    public function __construct(private readonly LicenseKeyGenerator $keys) {}

    /**
     * @return LengthAwarePaginator<int, License>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return License::query()
            ->with(['company', 'subscription.plan.product'])
            ->withCount('activeActivations')
            ->latest()
            ->paginate($perPage);
    }

    /** Issue a brand-new license for a subscription. */
    public function issueForSubscription(Subscription $subscription, ?string $actorId = null, ?int $maxActivations = null): License
    {
        $default = config('licenses.default_max_activations');

        $license = License::create([
            'subscription_id' => $subscription->id,
            'company_id' => $subscription->company_id,
            'key' => $this->keys->generate(),
            'status' => LicenseStatus::Active,
            'max_activations' => $maxActivations ?? (is_int($default) ? $default : 1),
            'expires_at' => $subscription->ends_at,
            'issued_at' => Carbon::now(),
        ]);

        $this->record($license, LicenseEventType::Issued, $actorId);

        return $license;
    }

    /**
     * Idempotently ensure a subscription has a current license. Creates one on
     * first activation; on renewal, extends the existing license's expiry (and
     * reactivates it if it had lapsed). Revoked licenses are never resurrected.
     */
    public function syncForSubscription(Subscription $subscription, ?string $actorId = null): License
    {
        $license = License::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', '!=', LicenseStatus::Revoked->value)
            ->latest('id')
            ->first();

        if ($license === null) {
            return $this->issueForSubscription($subscription, $actorId);
        }

        $license->expires_at = $subscription->ends_at;
        if ($license->status === LicenseStatus::Expired) {
            $license->status = LicenseStatus::Active;
        }
        $license->save();

        $this->record($license, LicenseEventType::Renewed, $actorId);

        return $license;
    }

    public function suspend(License $license, ?string $actorId = null): License
    {
        $license->status = LicenseStatus::Suspended;
        $license->save();

        $this->record($license, LicenseEventType::Suspended, $actorId);

        return $license;
    }

    public function reactivate(License $license, ?string $actorId = null): License
    {
        $license->status = LicenseStatus::Active;
        $license->save();

        $this->record($license, LicenseEventType::Reactivated, $actorId);

        return $license;
    }

    public function revoke(License $license, ?string $actorId = null): License
    {
        $license->status = LicenseStatus::Revoked;
        $license->revoked_at = Carbon::now();
        $license->save();

        $this->record($license, LicenseEventType::Revoked, $actorId);

        return $license;
    }

    /**
     * Claim an activation slot for a device/domain. Idempotent per identifier: an
     * already-active identifier just refreshes its `last_seen_at`; a previously
     * deactivated one is reactivated in place. Enforces `max_activations` and
     * requires a currently-valid license.
     *
     * @throws ValidationException when the license is not usable or the limit is reached
     */
    public function activate(
        License $license,
        IdentifierType $type,
        string $identifier,
        ?string $name = null,
        ?string $actorId = null,
        ?string $actorType = null,
    ): LicenseActivation {
        if (! $license->isCurrentlyValid()) {
            throw ValidationException::withMessages([
                'license' => __('This license is not active.'),
            ]);
        }

        $existing = $license->activations()->where('identifier', $identifier)->first();

        if ($existing !== null && $existing->isActive()) {
            $existing->forceFill(['last_seen_at' => Carbon::now()])->save();

            return $existing;
        }

        if ($license->activeActivations()->count() >= $license->max_activations) {
            throw ValidationException::withMessages([
                'identifier' => __('This license has reached its activation limit of :max.', [
                    'max' => $license->max_activations,
                ]),
            ]);
        }

        if ($existing !== null) {
            $existing->forceFill([
                'identifier_type' => $type,
                'name' => $name,
                'activated_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'revoked_at' => null,
            ])->save();
            $activation = $existing;
        } else {
            $activation = $license->activations()->create([
                'identifier_type' => $type,
                'identifier' => $identifier,
                'name' => $name,
                'activated_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
            ]);
        }

        $this->record($license, LicenseEventType::Activated, $actorId, [
            'identifier' => $identifier,
            'identifier_type' => $type->value,
        ], $actorType);

        return $activation;
    }

    /** Release an activation's slot. Idempotent — a no-op if already deactivated. */
    public function deactivate(LicenseActivation $activation, ?string $actorId = null, ?string $actorType = null): LicenseActivation
    {
        if ($activation->isActive()) {
            $activation->forceFill(['revoked_at' => Carbon::now()])->save();

            $this->record($activation->license, LicenseEventType::Deactivated, $actorId, [
                'identifier' => $activation->identifier,
            ], $actorType);
        }

        return $activation;
    }

    /**
     * Resolve a license by its key on behalf of a product, enforcing that the
     * license belongs to that product (via subscription → plan → product). A miss
     * or a cross-product access both surface as "not found" — existence is never
     * leaked to a product that does not own the license.
     *
     * @throws ModelNotFoundException<License>
     */
    public function resolveForProduct(string $key, int $productId): License
    {
        $license = License::query()
            ->with('subscription.plan.product')
            ->where('key', $key)
            ->whereHas('subscription.plan', function ($query) use ($productId): void {
                $query->where('product_id', $productId);
            })
            ->first();

        if ($license === null) {
            throw (new ModelNotFoundException)->setModel(License::class);
        }

        return $license;
    }

    /**
     * Record that a device/domain checked in during online validation: refresh the
     * matching active activation's `last_seen_at`. No-op if the identifier is not a
     * live activation. Returns the matched activation, if any.
     */
    public function heartbeat(License $license, ?string $identifier): ?LicenseActivation
    {
        if ($identifier === null) {
            return null;
        }

        $activation = $license->activeActivations()
            ->where('identifier', $identifier)
            ->first();

        $activation?->forceFill(['last_seen_at' => Carbon::now()])->save();

        return $activation;
    }

    /** Append a `token_issued` event when an offline license token is signed (ADR 0005). */
    public function noteOfflineTokenIssued(License $license, string $identifier, ?string $actorId, ?string $actorType = null): void
    {
        $this->record($license, LicenseEventType::TokenIssued, $actorId, ['identifier' => $identifier], $actorType);
    }

    /** Mark active licenses past their expiry as expired. Returns the count. */
    public function expireDue(): int
    {
        return License::query()
            ->where('status', LicenseStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => LicenseStatus::Expired->value]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(License $license, LicenseEventType $type, ?string $actorId, array $context = [], ?string $actorType = null): void
    {
        LicenseEvent::create([
            'license_id' => $license->id,
            'event_type' => $type,
            'actor_type' => $actorType ?? ($actorId !== null ? 'user' : 'system'),
            'actor_id' => $actorId,
            'context' => $context === [] ? null : $context,
        ]);
    }
}
