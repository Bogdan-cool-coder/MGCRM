<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\OnboardingAiSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingAiSession>
 */
class OnboardingAiSessionFactory extends Factory
{
    protected $model = OnboardingAiSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'lesson_id' => Lesson::factory(),
            'messages' => [],
        ];
    }

    /**
     * State: session with one completed exchange (user question + assistant answer).
     */
    public function withMessages(int $pairs = 1): static
    {
        $messages = [];
        for ($i = 0; $i < $pairs; $i++) {
            $messages[] = [
                'role' => 'user',
                'content' => $this->faker->sentence().'?',
                'created_at' => now()->toISOString(),
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->faker->paragraph(),
                'created_at' => now()->toISOString(),
            ];
        }

        return $this->state(['messages' => $messages]);
    }
}
