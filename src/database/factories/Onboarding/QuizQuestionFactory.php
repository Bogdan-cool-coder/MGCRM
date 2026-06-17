<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Enums\QuestionKind;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    protected $model = QuizQuestion::class;

    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'text' => $this->faker->sentence().'?',
            'kind' => QuestionKind::SingleChoice->value,
            'sort_order' => 1,
            'explanation' => $this->faker->optional()->sentence(),
            'points' => 1,
        ];
    }

    public function singleChoice(): static
    {
        return $this->state(['kind' => QuestionKind::SingleChoice->value]);
    }

    public function multipleChoice(): static
    {
        return $this->state(['kind' => QuestionKind::MultipleChoice->value]);
    }
}
