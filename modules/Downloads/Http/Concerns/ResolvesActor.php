<?php

namespace Modules\Downloads\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Users\Domain\Models\User;

/**
 * Resolves the acting staff user's UUID for the download ledger, or null when
 * the action is system-driven.
 */
trait ResolvesActor
{
    protected function actorId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->uuid : null;
    }
}
