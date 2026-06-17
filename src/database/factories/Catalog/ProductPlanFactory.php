<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\BillingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductPlan>
 */
class ProductPlanFactory extends Factory
{
    protected $model = ProductPlan::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement(['Start', 'Business', 'Enterprise', 'Pro', 'Basic']);

        return [
            'product_id' => Product::factory(),
            'code' => Str::slug($name).'_'.Str::random(4),
            'name' => $name,
            'unit' => BillingUnit::Year,
            'sort_order' => $this->faker->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
