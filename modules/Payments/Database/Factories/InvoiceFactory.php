<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Payments\Domain\Enums\InvoiceStatus;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now();

        return [
            'number' => 'INV-'.fake()->unique()->numerify('######'),
            'company_id' => Company::factory(),
            'subscription_id' => Subscription::factory(),
            'status' => InvoiceStatus::Open,
            'amount' => fake()->randomElement(['50.00', '120.00', '499.00']),
            'currency' => 'USD',
            'period_start' => $start,
            'period_end' => $start->copy()->addDays(30),
            'issued_at' => $start,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => Carbon::now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Void,
            'voided_at' => Carbon::now(),
        ]);
    }
}
