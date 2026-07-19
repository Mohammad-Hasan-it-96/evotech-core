<?php

namespace Modules\Downloads\Infrastructure\Locator;

use Illuminate\Support\Facades\Config;
use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;
use Modules\Downloads\Application\Services\DownloadService;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Products\Domain\Models\Product;

/**
 * Answers Core's ReleaseDownloadLocator port with permanent public URLs.
 *
 * Returns the `downloads.latest` route rather than a signed link on purpose: the
 * caller embeds these in a config file that apps cache for minutes and that sits
 * on devices for far longer. A signed link would be dead before most devices read
 * it, and would have to be regenerated on every fetch.
 */
final class ReleaseDownloadUrlLocator implements ReleaseDownloadLocator
{
    public function __construct(private readonly DownloadService $downloads) {}

    /**
     * @return array<int, array{platform: string, variant: string, url: string}>
     */
    public function latestDownloadUrls(string $productSlug, ?string $channel = null): array
    {
        $channelCase = ReleaseChannel::tryFrom(
            $channel ?? Config::string('downloads.default_channel'),
        );

        if ($channelCase === null) {
            return [];
        }

        $product = Product::query()->where('slug', $productSlug)->first();

        if ($product === null) {
            return [];
        }

        $release = $this->downloads->latestPublished($product->id, $channelCase);

        if ($release === null) {
            return [];
        }

        $urls = [];

        foreach ($release->artifacts as $artifact) {
            // Cast to the Platform enum on the model; the contract is strings, so
            // the backing value is what belongs here.
            $platform = $artifact->platform->value;
            $variant = $artifact->variant;

            /*
             * The URL deliberately does not name the artifact: it resolves to
             * whatever is currently published, so a link already sitting in a
             * cached config keeps working after the next release rather than
             * pointing at the previous build forever.
             */
            $parameters = ['product' => $productSlug, 'platform' => $platform];

            // Omitted rather than empty: `…/android/` is not the universal URL.
            if ($variant !== '') {
                $parameters['variant'] = $variant;
            }

            $urls[] = [
                'platform' => $platform,
                'variant' => $variant,
                'url' => route('api.v1.downloads.latest', $parameters),
            ];
        }

        return $urls;
    }
}
