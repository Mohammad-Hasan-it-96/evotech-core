<?php

namespace Modules\Licenses\Application\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Licenses\Application\DTO\IssuedOfflineToken;
use Modules\Licenses\Domain\Contracts\OfflineTokenSigner;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;

/**
 * Issues signed offline license tokens (ADR 0005). Builds the claim set from a
 * license + one of its active activations, clamps the token's lifetime to the
 * license's own expiry, signs it, and records the issuance in the ledger.
 *
 * Callers must first ensure the license is currently valid and the activation is
 * an active slot of that license.
 */
final class OfflineTokenService
{
    public function __construct(
        private readonly OfflineTokenSigner $signer,
        private readonly LicenseService $licenses,
    ) {}

    public function issue(
        License $license,
        LicenseActivation $activation,
        ?string $actorId = null,
        ?string $actorType = null,
    ): IssuedOfflineToken {
        $now = Carbon::now();

        $ttlDays = config('licenses.offline_tokens.ttl_days');
        $expiresAt = $now->copy()->addDays(is_int($ttlDays) && $ttlDays > 0 ? $ttlDays : 14);

        // Never outlive the license itself — a token cannot grant entitlement past
        // the license's own expiry.
        if ($license->expires_at !== null && $license->expires_at->lessThan($expiresAt)) {
            $expiresAt = $license->expires_at->copy();
        }

        $issuer = config('licenses.offline_tokens.issuer');

        $claims = [
            'iss' => is_string($issuer) ? $issuer : 'evotech-platform',
            'sub' => $license->key,
            'aud' => $license->subscription->plan->product->slug,
            'jti' => (string) Str::orderedUuid(),
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'license' => [
                'key' => $license->key,
                'status' => $license->status->value,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'max_activations' => $license->max_activations,
            ],
            'device' => [
                'identifier_type' => $activation->identifier_type->value,
                'identifier' => $activation->identifier,
            ],
        ];

        $token = $this->signer->sign($claims);

        $this->licenses->noteOfflineTokenIssued($license, $activation->identifier, $actorId, $actorType);

        return new IssuedOfflineToken(
            $token,
            $this->signer->algorithm(),
            $this->signer->keyId(),
            $now,
            $expiresAt,
        );
    }
}
