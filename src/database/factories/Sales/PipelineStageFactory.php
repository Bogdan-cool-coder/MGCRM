<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => ucwords($name),
            'code' => Str::slug($name, '_').'_'.Str::random(4),
            'sort_order' => $this->faker->numberBetween(1, 20),
            'color' => '#'.substr(md5((string) $this->faker->randomNumber()), 0, 6),
            'is_won' => false,
            'is_lost' => false,
            'hidden_by_default' => false,
            'parent_stage_id' => null,
            'stage_features' => [],
            'won_gate' => false,
            'sla_hours' => null,
            'visible_department_ids' => null,
            'visible_user_ids' => null,
        ];
    }

    public function won(): static
    {
        return $this->state(['is_won' => true, 'won_gate' => true]);
    }

    public function lost(): static
    {
        return $this->state(['is_lost' => true, 'hidden_by_default' => true]);
    }
}
