<?php

declare(strict_types=1);

namespace Database\Factories\Automation;

use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRun>
 */
class AutomationRunFactory extends Factory
{
    protected $model = AutomationRun::class;

    public function definition(): array
    {
        return [
            'automation_id' => PipelineAutomation::factory(),
            'target_type' => AutomationTargetType::Deal->value,
            'target_id' => $this->faker->numberBetween(1, 1000),
            'status' => RunStatus::Success->value,
            // Manual/seed runs carry a null idem slot so they never conflict.
            'trigger_event_ts' => null,
            'payload' => null,
            'result' => ['summary' => 'ok'],
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
        ];
    }

    public function status(RunStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }

    public function forTarget(int $targetId): static
    {
        return $this->state(['target_id' => $targetId]);
    }
}
