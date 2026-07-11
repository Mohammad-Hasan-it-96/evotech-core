<?php

namespace Modules\Downloads\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Http\Resources\DownloadEventResource;

/**
 * Staff-facing, read-only view of the download ledger (auth:sanctum).
 */
final class DownloadEventController extends ApiController
{
    public function __construct(private readonly DownloadService $downloads) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return DownloadEventResource::collection($this->downloads->eventsPaginate(
            $request->filled('artifact') ? (string) $request->string('artifact') : null,
            $perPage,
        ));
    }
}
