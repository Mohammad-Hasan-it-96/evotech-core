<?php

namespace Modules\Core\Domain\Contracts;

/**
 * Resolves a product's current published download URLs (ADR 0008 + §2.4).
 *
 * Exists so a module can offer "the latest build" without depending on the
 * Downloads module — same shape as the AuditLogger port: Core binds a safe no-op,
 * and Downloads supplies the adapter that actually answers. The consumer today is
 * DeviceSubscriptions, whose remote-config carries download links for the shipped
 * consumer apps.
 *
 * URLs returned here are **permanent** — they name "the current build for a
 * platform", not a file, and resolve at request time. That is what makes them safe
 * to embed in a config file the apps cache for minutes at a time, unlike the
 * short-lived signed links used for authenticated self-update.
 */
interface ReleaseDownloadLocator
{
    /**
     * Permanent download URLs for a product's latest published release, keyed by
     * platform (`android`, `windows`, …).
     *
     * Returns an empty array when the product is unknown, has no published
     * release, or that release has no artifacts — all normal states, none of them
     * exceptional. A caller uses the result to *fill in* links, so "nothing to
     * offer" and "something went wrong" would be handled identically anyway.
     *
     * @param  string|null  $channel  Null uses the configured default channel.
     * @return array<string, string>
     */
    public function latestDownloadUrls(string $productSlug, ?string $channel = null): array;
}
