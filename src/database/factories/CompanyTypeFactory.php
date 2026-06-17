<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\Models\CompanyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyType>
 */
class CompanyTypeFactory extends Factory
{
    protected $model = CompanyType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
