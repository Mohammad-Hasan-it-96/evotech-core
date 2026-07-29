<?php

namespace Modules\DeviceSubscriptions\Http\Controllers\Sync;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Modules\DeviceSubscriptions\Application\Services\SyncEnrollmentService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceJoinToken;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Delivers a bootstrap snapshot's bytes to a joining device (ADR 0011, Decision 13).
 *
 * Reachable only through a valid, unexpired signed URL (the `signed` middleware) —
 * the signature is the credential, minted for the enrolling device by
 * {@see SyncEnrollmentService}.
 * The snapshot is deleted after it is sent: it is a transient seed, never retained
 * (Decision 13 non-goal). The joiner verifies the file against the owner-computed
 * SHA-256 it received at enrollment, so the integrity check is end-to-end (H2).
 *
 * Assumes a LOCAL private disk (delete-after-send needs a real path); pointing the
 * snapshot disk at S3 later would move the download to a streamed response.
 */
final class BootstrapDownloadController
{
    public function __invoke(DeviceJoinToken $joinToken): BinaryFileResponse
    {
        abort_if($joinToken->snapshot_path === null, 404);

        $disk = Storage::disk(Config::string('device-subscriptions.sync.snapshot_disk'));

        abort_unless($disk->exists($joinToken->snapshot_path), 404);

        return response()
            ->download($disk->path($joinToken->snapshot_path), 'bootstrap.sqlite')
            ->deleteFileAfterSend();
    }
}
