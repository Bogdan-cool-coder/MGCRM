<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductGroup>
 */
class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
