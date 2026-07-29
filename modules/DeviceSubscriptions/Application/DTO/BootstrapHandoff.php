<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

/**
 * The seed a joining device receives so it does not come up blank (ADR 0011,
 * Decision 13).
 *
 * `cursor` is the seq the joiner pulls from — the OWNER'S OWN local pull cursor,
 * captured before its VACUUM, never a server-side last_seq (the 2026-07-29 fix).
 * `snapshotUrl`/`snapshotSha256` are present only when the owner attached a
 * snapshot; a first/only device joins an empty shop with cursor 0 and no snapshot.
 */
final readonly class BootstrapHandoff
{
    public function __construct(
        public int $cursor,
        public ?string $snapshotUrl = null,
        public ?string $snapshotSha256 = null,
    ) {}
}
