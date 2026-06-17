<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' LLC',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'country_code' => 'kz',
            'city' => fake()->city(),
            'source' => 'own_contact',
            'tags' => [],
            'extra_fields' => [],
        ];
    }
}
