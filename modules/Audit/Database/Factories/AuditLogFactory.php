<?php

namespace Modules\Audit\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Audit\Domain\Models\AuditLog;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => fake()->randomElement(['auth.login', 'invoice.paid', 'subscription.activated']),
            'actor_type' => 'user',
            'actor_id' => fake()->uuid(),
            'subject_type' => null,
            'subject_id' => null,
            'context' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['actor_type' => 'system', 'actor_id' => null]);
    }
}
