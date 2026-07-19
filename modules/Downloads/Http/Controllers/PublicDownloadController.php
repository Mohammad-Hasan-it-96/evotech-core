<?php

namespace Modules\Downloads\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Products\Domain\Models\Product;

/**
 * GET /api/v1/downloads/latest/{product}/{platform} — a permanent download URL.
 *
 * ## Why this exists alongside signed links
 *
 * Every other route into the Download Center mints a 15-minute signed URL, which
 * is right for a licensed product self-updating: it is authenticated, per-product,
 * and audited. It is useless for the one thing a consumer app needs — a URL that
 * can sit inside a cached remote-config file, or in a message sent to a customer,
 * and still work tomorrow.
 *
 * This URL never expires because it does not name a file. It names *the current
 * build for a platform*, and resolves at request time. Publishing 1.0.1 changes
 * what it serves with no config edit and no link to reissue — every already-cached
 * copy starts pointing at the new build on its own.
 *
 * ## Why it redirects rather than streaming
 *
 * It mints a signed link and 302s to it. That keeps exactly one route serving
 * bytes (and therefore one place where disk access, filenames and missing files
 * are handled), and reuses the download ledger unchanged. Browsers and Android's
 * download manager both follow it transparently.
 *
 * ## Why it is public
 *
 * The artifacts it can reach are, by definition, published releases of a product —
 * the builds already handed to anyone who asks. Requiring auth would mean the
 * consumer apps could not use it at all, which is the entire point. Draft and
 * archived releases remain unreachable.
 */
final class PublicDownloadController
{
    public function __construct(private readonly DownloadService $downloads) {}

    public function __invoke(Request $request, string $product, string $platform): RedirectResponse
    {
        $platformCase = Platform::tryFrom(mb_strtolower($platform));

        abort_if($platformCase === null, 404);

        $channel = ReleaseChannel::tryFrom(
            $request->filled('channel')
                ? mb_strtolower((string) $request->string('channel'))
                : Config::string('downloads.default_channel'),
        );

        abort_if($channel === null, 404);

        $productModel = Product::query()->where('slug', $product)->first();

        abort_if($productModel === null, 404);

        $release = $this->downloads->latestPublished($productModel->id, $channel, $platformCase);

        abort_if($release === null, 404);

        /*
         * `latestPublished` filters releases *having* an artifact for the platform,
         * so this cannot miss — but it is resolved explicitly rather than assumed,
         * because a null here would otherwise surface as a type error on a public,
         * unauthenticated route.
         */
        $artifact = $release->artifacts->firstWhere('platform', $platformCase->value);

        abort_if(! $artifact instanceof Artifact, 404);

        $link = $this->downloads->issueLink(
            $artifact,
            'public',
            null,
            null,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->away($link->url);
    }
}
