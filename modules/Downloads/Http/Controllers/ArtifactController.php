<?php

namespace Modules\Downloads\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Downloads\Http\Concerns\ResolvesActor;
use Modules\Downloads\Http\Requests\UploadArtifactRequest;
use Modules\Downloads\Http\Resources\ArtifactResource;

/**
 * Staff-facing artifact management (auth:sanctum): upload/replace a release's
 * per-platform binaries, remove them, and mint signed download links.
 */
final class ArtifactController extends ApiController
{
    use ResolvesActor;

    public function __construct(private readonly DownloadService $downloads) {}

    public function index(Release $release): AnonymousResourceCollection
    {
        return ArtifactResource::collection($release->artifacts()->get());
    }

    public function store(UploadArtifactRequest $request, Release $release): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        $artifact = $this->downloads->storeArtifact(
            $release,
            $file,
            Platform::from((string) $request->string('platform')),
            $request->variant(),
            $this->actorId($request),
        );

        return ArtifactResource::make($artifact)->response()->setStatusCode(201);
    }

    public function destroy(Artifact $artifact): JsonResponse
    {
        $this->downloads->deleteArtifact($artifact);

        return $this->noContent();
    }

    /** Mint a short-lived signed download URL for staff (recorded to the ledger). */
    public function link(Request $request, Artifact $artifact): JsonResponse
    {
        $link = $this->downloads->issueLink(
            $artifact,
            'staff',
            $this->actorId($request),
            null,
            $request->ip(),
            $request->userAgent(),
        );

        return $this->ok([
            'url' => $link->url,
            'expires_at' => $link->expiresAt->toIso8601String(),
        ]);
    }
}
