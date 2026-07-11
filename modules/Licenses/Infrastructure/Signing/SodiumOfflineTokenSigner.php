<?php

namespace Modules\Licenses\Infrastructure\Signing;

use Modules\Licenses\Domain\Contracts\OfflineTokenSigner;
use RuntimeException;

/**
 * EdDSA (Ed25519) JWS signer built on native ext-sodium — no crypto library
 * dependency (ADR 0005). Keys are managed secrets read from config (§6.10).
 */
final class SodiumOfflineTokenSigner implements OfflineTokenSigner
{
    public function algorithm(): string
    {
        return 'EdDSA';
    }

    public function keyId(): string
    {
        $kid = config('licenses.offline_tokens.key_id');

        return is_string($kid) && $kid !== '' ? $kid : 'evo-license-key-1';
    }

    public function sign(array $claims): string
    {
        $header = ['typ' => 'JWT', 'alg' => $this->algorithm(), 'kid' => $this->keyId()];

        $signingInput = $this->base64Url($this->json($header)).'.'.$this->base64Url($this->json($claims));
        $signature = sodium_crypto_sign_detached($signingInput, $this->secretKey());

        return $signingInput.'.'.$this->base64Url($signature);
    }

    public function publicJwk(): array
    {
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'use' => 'sig',
            'alg' => $this->algorithm(),
            'kid' => $this->keyId(),
            'x' => $this->base64Url($this->publicKey()),
        ];
    }

    /** @return non-empty-string */
    private function secretKey(): string
    {
        return $this->decodeKey('private_key', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    }

    /** @return non-empty-string */
    private function publicKey(): string
    {
        return $this->decodeKey('public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    /** @return non-empty-string */
    private function decodeKey(string $name, int $expectedBytes): string
    {
        $encoded = config("licenses.offline_tokens.{$name}");

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException("License offline-token {$name} is not configured.");
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || $raw === '' || strlen($raw) !== $expectedBytes) {
            throw new RuntimeException("License offline-token {$name} is invalid.");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
