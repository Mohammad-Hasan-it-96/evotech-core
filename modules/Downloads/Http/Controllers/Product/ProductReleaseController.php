<?php

namespace Modules\Downloads\Http\Controllers\Product;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Http\Requests\Product\LatestReleaseRequest;
use Modules\Downloads\Http\Resources\ReleaseResource;
use Modules\Gateway\Domain\Contracts\ProductContext;

/**
 * Product-facing Download Center (auth:product, ADR 0004). A product checks for
 * its latest published release and mints a signed download URL — always scoped
 * to its own product; any other artifact is 404, mirroring the Licenses product
 * endpoints.
 */
final class ProductReleaseController extends ApiController
{
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly ProductContext $product,
    ) {}

    /** The latest published release on a channel (auto-update check). */
    public function latest(LatestReleaseRequest $request): ReleaseResource
    {
        $channel = ReleaseChannel::from(
            $request->filled('channel')
                ? (string) $request->string('channel')
                : Config::string('downloads.default_channel'),
        );

        $platform = $request->filled('platform')
            ? Platform::from((string) $request->string('platform'))
            : null;

        $release = $this->downloads->latestPublished((int) $this->product->productId(), $channel, $platform);

        abort_if($release === null, 404);

        return ReleaseResource::make($release);
    }

    /** Mint a short-lived signed download URL for one of the product's own artifacts. */
    public function link(Request $request, Artifact $artifact): JsonResponse
    {
        $artifact->loadMissing('release');

        abort_unless($artifact->release->product_id === $this->product->productId(), 404);

        /*
         * Owning the product is not enough — the release must actually be
         * published. `latest` only ever surfaces published releases, so a product
         * has no legitimate way to learn an unpublished artifact's uuid; but uuid7
         * is time-ordered and a product that has seen one artifact can reason about
         * its neighbours. Without this check, knowing (or guessing) a uuid was
         * enough to pull an unreleased build.
         *
         * 404 rather than 403, matching the cross-product case: a product should
         * not be able to distinguish "exists but not yours" from "does not exist".
         */
        abort_unless($artifact->release->isPublished(), 404);

        $link = $this->downloads->issueLink(
            $artifact,
            'product',
            $this->product->productSlug(),
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
