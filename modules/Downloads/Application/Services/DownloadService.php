<?php

namespace Modules\Downloads\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Downloads\Application\DTO\IssuedDownloadLink;
use Modules\Downloads\Domain\ArtifactFormats;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Downloads\Domain\Events\ReleasePublished;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\DownloadEvent;
use Modules\Downloads\Domain\Models\Release;
use Modules\Products\Domain\Models\Product;

/**
 * Download Center use-cases (ADR 0008): staff manage releases + upload
 * artifacts; products discover the latest release; both obtain files only via
 * short-lived signed URLs, each issue recorded to the immutable download ledger.
 */
final class DownloadService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return LengthAwarePaginator<int, Release>
     */
    public function paginate(?string $product, ?string $channel, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = Release::query()
            ->with('product')
            ->withCount('artifacts')
            ->latest();

        if ($product !== null) {
            $query->whereHas('product', fn ($p) => $p->where('slug', $product));
        }

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function createRelease(Product $product, ReleaseChannel $channel, string $version, ?string $name, ?string $notes): Release
    {
        return Release::create([
            'product_id' => $product->id,
            'channel' => $channel,
            'version' => $version,
            'name' => $name,
            'notes' => $notes,
            'status' => ReleaseStatus::Draft,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRelease(Release $release, array $attributes): Release
    {
        $release->fill($attributes)->save();

        return $release;
    }

    /**
     * Publish a release — requires at least one artifact.
     *
     * `$syncAppVersion` rides the ReleasePublished event: when set, a consumer app
     * linked to this product aligns its advertised update version to this release
     * (handled in DeviceSubscriptions, not here — §2.4).
     */
    public function publish(Release $release, ?string $actorId = null, bool $syncAppVersion = false): Release
    {
        if ($release->artifacts()->count() === 0) {
            throw ValidationException::withMessages([
                'release' => 'A release must have at least one artifact before it can be published.',
            ]);
        }

        $release->update([
            'status' => ReleaseStatus::Published,
            'published_at' => $release->published_at ?? Carbon::now(),
        ]);

        $this->audit->log('release.published', 'release', $release->uuid, [
            'product' => $release->product->slug,
            'channel' => $release->channel->value,
            'version' => $release->version,
        ], $actorId);

        ReleasePublished::dispatch($release->product->slug, $release->version, $syncAppVersion);

        return $release;
    }

    public function archive(Release $release): Release
    {
        $release->update(['status' => ReleaseStatus::Archived]);

        return $release;
    }

    /**
     * Bring an archived release back, as a draft.
     *
     * Back to *draft* rather than straight to published, even though most
     * archived releases were published once: restoring is undoing a mistake, and
     * the mistake is often archiving the wrong row. Landing in draft means that
     * slip cannot silently republish a build to every device on the public
     * download URL — re-publishing stays the deliberate act it is everywhere else.
     */
    public function unarchive(Release $release): Release
    {
        $release->update(['status' => ReleaseStatus::Draft]);

        return $release;
    }

    public function deleteRelease(Release $release): void
    {
        foreach ($release->artifacts()->get() as $artifact) {
            $this->deleteArtifact($artifact);
        }

        $release->delete();
    }

    /**
     * Store an uploaded artifact on the private disk. The file's SHA-256 checksum
     * and content-detected MIME type are recorded (ADR 0008, §16.7). Re-uploading
     * a platform replaces its file.
     */
    public function storeArtifact(
        Release $release,
        UploadedFile $file,
        Platform $platform,
        string $variant = '',
        ?string $actorId = null,
    ): Artifact {
        $artifact = $this->persistArtifact(
            $release,
            (string) $file->getRealPath(),
            $file->getClientOriginalName(),
            $platform,
            $variant,
        );

        $this->audit->log('artifact.uploaded', 'artifact', $artifact->uuid, [
            'release' => $release->uuid,
            'platform' => $platform->value,
            'variant' => $variant,
        ], $actorId);

        return $artifact;
    }

    /**
     * Register a build already sitting in the incoming directory.
     *
     * The file is copied onto the delivery disk and only then removed from
     * staging — so a failure part-way leaves the original where the operator put
     * it, rather than losing a build that took minutes to transfer.
     */
    public function importArtifact(
        Release $release,
        string $filename,
        Platform $platform,
        string $variant = '',
        ?string $actorId = null,
    ): Artifact {
        $source = $this->incomingPath($filename);

        if (! is_file($source)) {
            throw ValidationException::withMessages([
                'filename' => 'That file is no longer in the incoming directory.',
            ]);
        }

        $artifact = $this->persistArtifact($release, $source, basename($filename), $platform, $variant);

        @unlink($source);

        $this->audit->log('artifact.imported', 'artifact', $artifact->uuid, [
            'release' => $release->uuid,
            'platform' => $platform->value,
            'variant' => $variant,
            'source' => basename($filename),
        ], $actorId);

        return $artifact;
    }

    /**
     * Builds sitting in the incoming directory, newest first.
     *
     * Only files the artifact allowlist would accept are listed: a stray `.txt`
     * or a half-finished transfer is noise an operator would have to reason
     * about, and offering it only to reject it on submit is worse than not
     * offering it.
     *
     * @return list<array{filename: string, size: int, modified_at: string}>
     */
    public function incomingFiles(): array
    {
        $directory = Config::string('downloads.incoming_path');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || ! ArtifactFormats::allows($entry)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path)) {
                continue;
            }

            $files[] = [
                'filename' => $entry,
                'size' => (int) filesize($path),
                'modified_at' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            ];
        }

        usort($files, fn (array $a, array $b) => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /**
     * Resolve a name within the incoming directory.
     *
     * `basename` strips every directory component, so a crafted `filename` cannot
     * walk out of the staging folder and register `.env` — or any other file on
     * the server — as a publicly downloadable artifact.
     */
    public function incomingPath(string $filename): string
    {
        return Config::string('downloads.incoming_path').DIRECTORY_SEPARATOR.basename($filename);
    }

    /**
     * Copy a local file onto the delivery disk and record it, replacing any
     * existing build of the same platform *and* variant — an arm64 upload must
     * sit alongside the armeabi one, not replace it.
     */
    private function persistArtifact(
        Release $release,
        string $localPath,
        string $filename,
        Platform $platform,
        string $variant,
    ): Artifact {
        $disk = Config::string('downloads.disk');
        $checksum = (string) hash_file('sha256', $localPath);
        $size = (int) filesize($localPath);
        $contentType = (new File($localPath))->getMimeType();
        $directory = "artifacts/{$release->product->slug}/{$release->uuid}";

        $path = (string) Storage::disk($disk)->putFile($directory, new File($localPath));

        // The unique index on (release_id, platform, variant) does not carry
        // deleted_at, so a *soft-deleted* build of this slot still occupies it.
        // Look with `withTrashed()` and resurrect that row — a plain
        // updateOrCreate would miss it (SoftDeletes scope) and then collide with
        // the surviving index entry on insert.
        $existing = $release->artifacts()
            ->withTrashed()
            ->where('platform', $platform->value)
            ->where('variant', $variant)
            ->first();

        // Only a live row has a file worth cleaning up; a trashed row's file was
        // already removed when it was deleted.
        $oldDisk = $existing !== null && ! $existing->trashed() ? $existing->disk : null;
        $oldPath = $existing !== null && ! $existing->trashed() ? $existing->path : null;

        $attributes = [
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'size' => $size,
            'checksum_sha256' => $checksum,
            'content_type' => $contentType,
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->deleted_at = null;
            $existing->save();
            $artifact = $existing;
        } else {
            $artifact = $release->artifacts()->create([
                'platform' => $platform->value,
                'variant' => $variant,
                ...$attributes,
            ]);
        }

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk((string) $oldDisk)->delete($oldPath);
        }

        return $artifact;
    }

    public function deleteArtifact(Artifact $artifact): void
    {
        Storage::disk($artifact->disk)->delete($artifact->path);

        $artifact->delete();
    }

    /**
     * The latest published release for a product on a channel (auto-update check).
     *
     * `$variant` only narrows when a platform is given — on its own it would ask
     * "a release with an arm64-v8a build of anything", which is not a question
     * anyone means.
     */
    public function latestPublished(
        int $productId,
        ReleaseChannel $channel,
        ?Platform $platform = null,
        ?string $variant = null,
    ): ?Release {
        $query = Release::query()
            ->where('product_id', $productId)
            ->where('channel', $channel->value)
            ->where('status', ReleaseStatus::Published->value)
            ->with(['artifacts', 'product'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($platform !== null) {
            $query->whereHas('artifacts', function ($a) use ($platform, $variant) {
                $a->where('platform', $platform->value);

                if ($variant !== null) {
                    $a->where('variant', $variant);
                }
            });
        }

        return $query->first();
    }

    /**
     * Mint a short-lived signed download URL and record the issue to the ledger
     * (ADR 0008 — issue-time is the auditable download event).
     */
    public function issueLink(
        Artifact $artifact,
        string $actorType,
        ?string $actorId = null,
        ?int $companyId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): IssuedDownloadLink {
        DB::transaction(function () use ($artifact, $actorType, $actorId, $companyId, $ip, $userAgent): void {
            DownloadEvent::create([
                'artifact_id' => $artifact->id,
                'company_id' => $companyId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            $artifact->increment('download_count');
        });

        $expiresAt = Carbon::now()->addMinutes(Config::integer('downloads.link_ttl_minutes'));

        $url = URL::temporarySignedRoute(
            'api.v1.downloads.deliver',
            $expiresAt,
            ['artifact' => $artifact->uuid],
        );

        return new IssuedDownloadLink($url, $expiresAt);
    }

    /**
     * @return LengthAwarePaginator<int, DownloadEvent>
     */
    public function eventsPaginate(?string $artifactUuid, int $perPage): LengthAwarePaginator
    {
        $query = DownloadEvent::query()
            ->with('artifact')
            ->latest();

        if ($artifactUuid !== null) {
            $query->whereHas('artifact', fn ($a) => $a->where('uuid', $artifactUuid));
        }

        return $query->paginate($perPage);
    }
}
