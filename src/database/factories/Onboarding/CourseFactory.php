<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->paragraph(),
            'cover_image_path' => null,
            'is_published' => false,
            'passing_score_pct' => 80,
            'completion_policy' => 'informational',
            'deadline_days' => null,
            'sort_order' => 0,
            'created_by_user_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }

    public function withDeadline(int $days = 30): static
    {
        return $this->state(['deadline_days' => $days]);
    }

    public function softGate(): static
    {
        return $this->state(['completion_policy' => 'soft_gate']);
    }
}
