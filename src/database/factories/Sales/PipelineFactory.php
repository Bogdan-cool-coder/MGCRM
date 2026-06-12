<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'kind' => PipelineKind::Sales,
            'settings' => [],
            'visible_role' => null,
            'visible_user_ids' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
