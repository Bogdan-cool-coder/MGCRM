<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Catalog\Models\Product;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentItem>
 */
class DocumentItemFactory extends Factory
{
    protected $model = DocumentItem::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(10000, 1000000); // 100–10000 currency units
        $qty = 1.0;

        return [
            'document_id' => Document::factory(),
            'product_id' => Product::factory(),
            'plan_id' => null,
            'name_snapshot' => $this->faker->words(3, true),
            'currency' => 'KZT',
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => (int) round($qty * $unitPrice),
            'sort_order' => 0,
        ];
    }
}
