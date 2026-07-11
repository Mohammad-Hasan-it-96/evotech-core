<?php

namespace Modules\Audit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Audit\Domain\Models\AuditLog;
use Modules\Audit\Http\Resources\AuditLogResource;
use Modules\Core\Http\Controllers\ApiController;

/**
 * Read-only access to the audit trail (staff, `auth:sanctum`). The log is
 * append-only — there is no create/update/delete endpoint (ADR 0007).
 */
final class AuditLogController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $query = AuditLog::query()->latest();

        if ($request->filled('action')) {
            $query->where('action', (string) $request->string('action'));
        }

        if ($request->filled('actor')) {
            $query->where('actor_id', (string) $request->string('actor'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', (string) $request->string('subject_type'));
        }

        return AuditLogResource::collection($query->paginate($perPage));
    }
}
