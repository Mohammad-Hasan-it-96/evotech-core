<?php

namespace Modules\Core\Infrastructure\Logging;

use Modules\Core\Domain\Contracts\AuditLogger;

/**
 * Safe default audit sink — discards entries. The Audit module overrides this
 * binding with a persisting adapter (ADR 0007); this keeps producers working if
 * Audit is ever absent.
 */
final class NullAuditLogger implements AuditLogger
{
    public function log(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $actorId = null,
    ): void {
        //
    }
}
