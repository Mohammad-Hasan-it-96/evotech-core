<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Domain\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => '50.00',
            'currency' => 'USD',
            'method' => PaymentMethod::BankTransfer,
            'gateway' => 'manual',
            'reference' => fake()->bothify('TXN-####-????'),
            'paid_at' => Carbon::now(),
        ];
    }
}
