<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Sales\Models\LostReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LostReason>
 */
class LostReasonFactory extends Factory
{
    protected $model = LostReason::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->sentence(2),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
