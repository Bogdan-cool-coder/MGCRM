<?php

declare(strict_types=1);

namespace Database\Factories\Activity;

use App\Domain\Activity\Models\MeetingReportQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingReportQuestion>
 */
class MeetingReportQuestionFactory extends Factory
{
    protected $model = MeetingReportQuestion::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => null, // global by default
            'text' => $this->faker->sentence(6).'?',
            'kind' => 'text',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function global(): static
    {
        return $this->state(['pipeline_id' => null]);
    }

    public function forPipeline(int $pipelineId): static
    {
        return $this->state(['pipeline_id' => $pipelineId]);
    }

    public function select(): static
    {
        return $this->state(['kind' => 'select']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
