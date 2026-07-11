<?php

namespace Modules\Licenses\Http\Controllers\Product;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Licenses\Domain\Contracts\OfflineTokenSigner;

/**
 * Publishes the public key(s) devices use to verify offline license tokens
 * (ADR 0005). Intentionally public — a verification key is not a secret — and
 * throttled by the standard `api` limiter. Devices fetch and cache it while
 * online; the `kid` on each JWK supports key rotation.
 */
final class SigningKeyController extends ApiController
{
    public function __construct(private readonly OfflineTokenSigner $signer) {}

    public function __invoke(): JsonResponse
    {
        return $this->ok([
            'algorithm' => $this->signer->algorithm(),
            'keys' => [$this->signer->publicJwk()],
        ]);
    }
}
