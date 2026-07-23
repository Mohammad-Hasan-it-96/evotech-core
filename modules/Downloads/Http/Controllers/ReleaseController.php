<?php

namespace Modules\Downloads\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Models\Release;
use Modules\Downloads\Http\Concerns\ResolvesActor;
use Modules\Downloads\Http\Requests\StoreReleaseRequest;
use Modules\Downloads\Http\Requests\UpdateReleaseRequest;
use Modules\Downloads\Http\Resources\ReleaseResource;
use Modules\Products\Domain\Models\Product;

/**
 * Staff-facing release management (auth:sanctum). Products consume releases
 * through the separate product-facing endpoints.
 */
final class ReleaseController extends ApiController
{
    use ResolvesActor;

    private const WITH = ['product'];

    public function __construct(private readonly DownloadService $downloads) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return ReleaseResource::collection($this->downloads->paginate(
            $request->filled('product') ? (string) $request->string('product') : null,
            $request->filled('channel') ? (string) $request->string('channel') : null,
            $request->filled('status') ? (string) $request->string('status') : null,
            $perPage,
        ));
    }

    public function store(StoreReleaseRequest $request): JsonResponse
    {
        $product = Product::query()->where('slug', (string) $request->string('product'))->firstOrFail();

        $release = $this->downloads->createRelease(
            $product,
            ReleaseChannel::from((string) $request->string('channel')),
            (string) $request->string('version'),
            $request->filled('name') ? (string) $request->string('name') : null,
            $request->filled('notes') ? (string) $request->string('notes') : null,
        );

        return $this->present($release)->response()->setStatusCode(201);
    }

    public function show(Release $release): ReleaseResource
    {
        return ReleaseResource::make($release->load([...self::WITH, 'artifacts']));
    }

    public function update(UpdateReleaseRequest $request, Release $release): ReleaseResource
    {
        return $this->present($this->downloads->updateRelease($release, $request->validated()));
    }

    public function publish(Request $request, Release $release): ReleaseResource
    {
        return $this->present($this->downloads->publish(
            $release,
            $this->actorId($request),
            $request->boolean('sync_app_version'),
        ));
    }

    public function archive(Request $request, Release $release): ReleaseResource
    {
        return $this->present($this->downloads->archive($release));
    }

    /** Restore an archived release to draft, so it can be published again. */
    public function unarchive(Request $request, Release $release): ReleaseResource
    {
        return $this->present($this->downloads->unarchive($release));
    }

    public function destroy(Release $release): JsonResponse
    {
        $this->downloads->deleteRelease($release);

        return $this->noContent();
    }

    private function present(Release $release): ReleaseResource
    {
        return ReleaseResource::make($release->load(self::WITH)->loadCount('artifacts'));
    }
}
