<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'name'          => ['ru' => 'Акция', 'en' => 'Promotion'],
            'description'   => null,
            'discount_type' => Promotion::TYPE_PERCENT,
            'discount_min'  => 0,
            'discount_max'  => 10,
            'is_active'     => true,
            'sort_order'    => null,
            // company_id / created_by are supplied by the caller (no orphan FK).
        ];
    }

    public function absolute(): static
    {
        return $this->state(fn () => [
            'discount_type' => Promotion::TYPE_ABSOLUTE,
            'discount_min'  => 0,
            'discount_max'  => 100000,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
