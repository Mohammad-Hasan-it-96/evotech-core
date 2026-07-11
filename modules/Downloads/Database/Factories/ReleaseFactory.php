<?php

namespace Modules\Downloads\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Downloads\Domain\Models\Release;
use Modules\Products\Domain\Models\Product;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'channel' => ReleaseChannel::Stable,
            'version' => fake()->unique()->numerify('#.#.#'),
            'name' => null,
            'notes' => fake()->optional()->sentence(),
            'status' => ReleaseStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStatus::Published,
            'published_at' => Carbon::now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReleaseStatus::Archived,
        ]);
    }

    public function channel(ReleaseChannel $channel): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => $channel,
        ]);
    }
}
