<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

/**
 * The outcome of a push batch: the per-row verdicts (in the order received) and
 * the business's resulting high-water seq.
 */
final readonly class PushResult
{
    /**
     * @param  list<AppliedChange>  $applied
     */
    public function __construct(
        public array $applied,
        public int $lastSeq,
    ) {}
}
