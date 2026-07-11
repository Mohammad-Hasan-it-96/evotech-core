<?php

namespace Modules\Licenses\Domain\Contracts;

/**
 * Signs offline license tokens and publishes the public key devices use to verify
 * them without connectivity (ADR 0005). The concrete algorithm/format is a
 * versioned contract — see the ADR before changing anything here.
 */
interface OfflineTokenSigner
{
    /** JWS `alg` value, e.g. "EdDSA". */
    public function algorithm(): string;

    /** Identifier of the signing key (`kid`), for rotation. */
    public function keyId(): string;

    /**
     * Sign a claim set into a compact JWS (`header.payload.signature`).
     *
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims): string;

    /**
     * The public verification key as an RFC 8037 JWK (`kty=OKP, crv=Ed25519`).
     *
     * @return array<string, string>
     */
    public function publicJwk(): array;
}
