<?php

namespace Modules\DeviceSubscriptions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DeviceSubscriptions\Domain\Models\DeviceNotification;

/**
 * @extends Factory<DeviceNotification>
 */
class DeviceNotificationFactory extends Factory
{
    protected $model = DeviceNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_name' => 'SmartAgent',
            'scope' => DeviceNotification::SCOPE_BROADCAST,
            'active_only' => false,
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->sentence(8),
            'type' => DeviceNotification::TYPE_CUSTOM,
            'recipients' => $this->faker->numberBetween(0, 50),
            'target_device_id' => null,
            'sent_by' => null,
            'sent_by_name' => 'Operator',
        ];
    }
}
