<?php

namespace Modules\Gateway\Application\Support;

use Illuminate\Support\Str;

/**
 * Mints and hashes product API tokens (ADR 0004). Token shape:
 * `evo_<prefix>_<secret>`. The full token is hashed with SHA-256 for storage;
 * the `evo_<prefix>` part is kept in the clear for display and never identifies
 * the secret on its own.
 */
final class ApiKeyGenerator
{
    private const PREFIX_LENGTH = 8;

    private const SECRET_LENGTH = 40;

    /** Hash a full plaintext token for lookup/storage (constant token, not a password). */
    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function generate(): GeneratedApiKey
    {
        $scheme = config('gateway.key_prefix');
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'evo';

        $prefix = $scheme.'_'.Str::lower(Str::random(self::PREFIX_LENGTH));
        $plaintext = $prefix.'_'.Str::random(self::SECRET_LENGTH);

        return new GeneratedApiKey($plaintext, $prefix, $this->hash($plaintext));
    }
}
