<?php

namespace Modules\Downloads\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;

/**
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    protected $model = Artifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->unique()->slug(2).'.zip';

        return [
            'release_id' => Release::factory(),
            'platform' => Platform::Any,
            'disk' => 'downloads',
            'path' => 'downloads/'.fake()->uuid().'/'.$filename,
            'filename' => $filename,
            'size' => fake()->numberBetween(1_024, 50_000_000),
            'checksum_sha256' => hash('sha256', fake()->unique()->uuid()),
            'content_type' => 'application/zip',
            'download_count' => 0,
        ];
    }

    public function platform(Platform $platform): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => $platform,
        ]);
    }
}
