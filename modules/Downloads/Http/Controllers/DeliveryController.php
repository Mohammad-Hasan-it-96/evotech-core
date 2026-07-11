<?php

namespace Modules\Downloads\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Modules\Downloads\Domain\Models\Artifact;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers an artifact's bytes. Reachable only through a valid, unexpired signed
 * URL (the `signed` middleware, ADR 0008) minted by the link endpoints — never a
 * public path. Streams from the artifact's private disk with its real filename.
 */
final class DeliveryController
{
    public function __invoke(Artifact $artifact): StreamedResponse
    {
        $disk = Storage::disk($artifact->disk);

        abort_unless($disk->exists($artifact->path), 404);

        return $disk->download($artifact->path, $artifact->filename);
    }
}
