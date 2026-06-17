<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory()->quiz(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'pass_score_pct' => 80,
            'time_limit_minutes' => null,
            'created_by_user_id' => null,
        ];
    }

    public function withTimeLimit(int $minutes = 30): static
    {
        return $this->state(['time_limit_minutes' => $minutes]);
    }
}
