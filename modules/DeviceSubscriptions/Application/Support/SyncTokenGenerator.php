<?php

namespace Modules\DeviceSubscriptions\Application\Support;

use Illuminate\Support\Str;

/**
 * Mints and hashes device-sync credentials (ADR 0011), reusing Gateway's ADR 0004
 * token shape so the security review is the same one already accepted.
 *
 * Two kinds of credential, both SHA-256-hashed for storage — the plaintext is
 * shown exactly once and never persisted:
 *
 *  - a SEAT token (`evosync_<prefix>_<secret>`) — the durable per-device sync
 *    credential; the `evosync_<prefix>` part is kept in the clear on the seat for
 *    display and does not identify the secret on its own.
 *  - a JOIN token (`evojoin_<secret>`) — the single-use enrollment token rendered
 *    as a QR by the owner device; looked up purely by hash, so it carries no stored
 *    prefix.
 */
final class SyncTokenGenerator
{
    private const SEAT_SCHEME = 'evosync';

    private const JOIN_SCHEME = 'evojoin';

    private const PREFIX_LENGTH = 8;

    private const SECRET_LENGTH = 40;

    /** Hash a full plaintext token for lookup/storage (constant token, not a password). */
    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** Mint a durable per-device seat credential. */
    public function generateSeatToken(): GeneratedSyncToken
    {
        $prefix = self::SEAT_SCHEME.'_'.Str::lower(Str::random(self::PREFIX_LENGTH));
        $plaintext = $prefix.'_'.Str::random(self::SECRET_LENGTH);

        return new GeneratedSyncToken($plaintext, $prefix, $this->hash($plaintext));
    }

    /**
     * Mint a single-use enrollment (join) token. Looked up by hash alone, so the
     * prefix is informational only and never stored.
     */
    public function generateJoinToken(): GeneratedSyncToken
    {
        $prefix = self::JOIN_SCHEME;
        $plaintext = $prefix.'_'.Str::random(self::SECRET_LENGTH);

        return new GeneratedSyncToken($plaintext, $prefix, $this->hash($plaintext));
    }
}
