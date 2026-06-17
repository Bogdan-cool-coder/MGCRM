<?php

declare(strict_types=1);

namespace Database\Factories\Automation;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineAutomation>
 */
class PipelineAutomationFactory extends Factory
{
    protected $model = PipelineAutomation::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'stage_id' => null, // whole-pipeline by default
            'name' => ucfirst($this->faker->words(3, true)),
            'description' => null,
            'trigger_kind' => TriggerKind::OnEnterStage,
            'trigger_config' => [],
            'action_kind' => ActionKind::CreateTask,
            'action_config' => [],
            'is_active' => true,
            'created_by_user_id' => null,
            'last_run_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function trigger(TriggerKind $kind): static
    {
        return $this->state(['trigger_kind' => $kind]);
    }
}
