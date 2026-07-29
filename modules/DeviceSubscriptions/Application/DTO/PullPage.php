<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

use Modules\DeviceSubscriptions\Domain\Models\DeviceChange;

/**
 * One page of a pull (ADR 0011, §9). `nextCursor` is the highest seq EXAMINED,
 * not the highest RETURNED — so a page made entirely of the caller's own echoed
 * changes still advances the cursor instead of looping forever.
 *
 * @property list<DeviceChange> $changes
 */
final readonly class PullPage
{
    /**
     * @param  list<DeviceChange>  $changes
     */
    public function __construct(
        public array $changes,
        public int $nextCursor,
        public bool $hasMore,
    ) {}
}
