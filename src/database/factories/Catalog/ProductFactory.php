<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\PricingType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'code' => Str::slug($name).'_'.Str::random(4),
            'name' => ucwords($name),
            'description' => $this->faker->optional()->paragraph(),
            'group_id' => null,
            'pricing_type' => PricingType::Fixed,
            'maps_to_product_code' => null,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function forGroup(ProductGroup $group): static
    {
        return $this->state(['group_id' => $group->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
