<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    protected $model = QuizAttempt::class;

    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'user_id' => User::factory(),
            'assignment_id' => null,
            'attempt_number' => 1,
            'score_pct' => null,
            'passed' => null,
            'answers' => [],
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    /** Closed (submitted) attempt. */
    public function finished(int $scorePct = 85, bool $passed = true): static
    {
        return $this->state([
            'score_pct' => $scorePct,
            'passed' => $passed,
            'finished_at' => now()->addMinutes(10),
        ]);
    }
}
