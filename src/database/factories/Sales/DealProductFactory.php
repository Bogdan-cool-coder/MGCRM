<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealProduct>
 */
class DealProductFactory extends Factory
{
    protected $model = DealProduct::class;

    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 5);
        $unitPrice = $this->faker->numberBetween(100_00, 1_000_000_00); // kopecks

        return [
            'deal_id' => fn () => Deal::factory(),
            'product_id' => fn () => Product::factory(),
            'plan_id' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'currency' => 'RUB',
            'amount' => $quantity * $unitPrice,
            'sort_order' => 0,
        ];
    }
}
