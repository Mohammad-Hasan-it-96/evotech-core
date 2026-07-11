<?php

namespace Modules\Companies\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Companies\Domain\Enums\CompanyStatus;
use Modules\Companies\Domain\Models\Company;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('09########'),
            'status' => CompanyStatus::Active,
        ];
    }
}
