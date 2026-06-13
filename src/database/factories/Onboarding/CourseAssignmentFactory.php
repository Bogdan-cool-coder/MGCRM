<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseAssignment>
 */
class CourseAssignmentFactory extends Factory
{
    protected $model = CourseAssignment::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'user_id' => User::factory(),
            'assigned_by_user_id' => null,
            'due_date' => null,
            'status' => 'pending',
            'completed_at' => null,
        ];
    }

    public function withDeadline(int $days = 14): static
    {
        return $this->state(['due_date' => now()->addDays($days)->endOfDay()]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => 'overdue',
            'due_date' => now()->subDay()->endOfDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}
