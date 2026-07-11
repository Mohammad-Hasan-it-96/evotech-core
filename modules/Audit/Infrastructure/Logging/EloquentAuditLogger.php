<?php

namespace Modules\Audit\Infrastructure\Logging;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Modules\Audit\Domain\Models\AuditLog;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Users\Domain\Models\User;

/**
 * Persists audit entries to the immutable `audit_logs` ledger (ADR 0007). Resolves
 * the current actor from the authenticated guard and the IP from the request,
 * unless an explicit actor is supplied.
 */
final class EloquentAuditLogger implements AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    public function log(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $actorId = null,
    ): void {
        $actorId ??= $this->currentActorId();

        AuditLog::create([
            'action' => $action,
            'actor_type' => $actorId !== null ? 'user' : 'system',
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->request->ip(),
        ]);
    }

    private function currentActorId(): ?string
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user->uuid : null;
    }
}
