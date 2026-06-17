<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        $supported = config('crm.currencies.supported', ['RUB', 'USD', 'KZT']);

        return [
            'product_id' => Product::factory(),
            'plan_id' => null,
            'currency_code' => $this->faker->randomElement($supported),
            'amount' => $this->faker->numberBetween(100_00, 500_000_00), // kopecks
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
}
