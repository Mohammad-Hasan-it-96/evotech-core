<?php

namespace Modules\Downloads\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Downloads\Application\DTO\IssuedDownloadLink;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
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

    /** Publish a release — requires at least one artifact. */
    public function publish(Release $release, ?string $actorId = null): Release
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

        return $release;
    }

    public function archive(Release $release): Release
    {
        $release->update(['status' => ReleaseStatus::Archived]);

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
    public function storeArtifact(Release $release, UploadedFile $file, Platform $platform, ?string $actorId = null): Artifact
    {
        $disk = Config::string('downloads.disk');
        $checksum = (string) hash_file('sha256', (string) $file->getRealPath());
        $size = (int) $file->getSize();
        $contentType = $file->getMimeType();
        $filename = $file->getClientOriginalName();
        $directory = "artifacts/{$release->product->slug}/{$release->uuid}";

        $path = (string) $file->store($directory, $disk);

        $existing = $release->artifacts()->where('platform', $platform->value)->first();
        $oldDisk = $existing?->disk;
        $oldPath = $existing?->path;

        $artifact = $release->artifacts()->updateOrCreate(
            ['platform' => $platform->value],
            [
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename,
                'size' => $size,
                'checksum_sha256' => $checksum,
                'content_type' => $contentType,
            ],
        );

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk((string) $oldDisk)->delete($oldPath);
        }

        $this->audit->log('artifact.uploaded', 'artifact', $artifact->uuid, [
            'release' => $release->uuid,
            'platform' => $platform->value,
        ], $actorId);

        return $artifact;
    }

    public function deleteArtifact(Artifact $artifact): void
    {
        Storage::disk($artifact->disk)->delete($artifact->path);

        $artifact->delete();
    }

    /** The latest published release for a product on a channel (auto-update check). */
    public function latestPublished(int $productId, ReleaseChannel $channel, ?Platform $platform = null): ?Release
    {
        $query = Release::query()
            ->where('product_id', $productId)
            ->where('channel', $channel->value)
            ->where('status', ReleaseStatus::Published->value)
            ->with(['artifacts', 'product'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($platform !== null) {
            $query->whereHas('artifacts', fn ($a) => $a->where('platform', $platform->value));
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
