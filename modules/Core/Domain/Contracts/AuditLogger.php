<?php

namespace Modules\Core\Domain\Contracts;

/**
 * The platform's audit port (ADR 0007). Any module records a security-relevant
 * action through this contract — never by depending on the Audit module. The
 * concrete adapter (and storage) is provided by the Audit module; Core binds a
 * safe no-op default so a missing binding never breaks a producer.
 */
interface AuditLogger
{
    /**
     * Record an action. `subjectType` is a label (e.g. "invoice") and `subjectId`
     * the subject's public uuid. `actorId` overrides the resolved current actor
     * (used when the actor is not yet the authenticated user, e.g. at login).
     *
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $actorId = null,
    ): void;
}
