<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizOption>
 */
class QuizOptionFactory extends Factory
{
    protected $model = QuizOption::class;

    public function definition(): array
    {
        return [
            'question_id' => QuizQuestion::factory(),
            'text' => $this->faker->sentence(4),
            'is_correct' => false,
            'sort_order' => 1,
        ];
    }

    public function correct(): static
    {
        return $this->state(['is_correct' => true]);
    }
}
