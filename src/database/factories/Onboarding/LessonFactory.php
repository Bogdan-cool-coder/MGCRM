<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'module_id' => CourseModule::factory(),
            'title' => $this->faker->sentence(4),
            'kind' => 'text',
            'content' => ['markdown' => $this->faker->paragraph()],
            'duration_minutes' => null,
            'sort_order' => 1,
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }

    public function video(): static
    {
        return $this->state([
            'kind' => 'video',
            'content' => [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'provider' => 'youtube',
            ],
        ]);
    }

    public function pdf(): static
    {
        return $this->state([
            'kind' => 'pdf',
            'content' => ['url' => 'https://example.com/document.pdf'],
        ]);
    }

    public function quiz(?int $quizId = null): static
    {
        return $this->state([
            'kind' => 'quiz',
            'content' => ['quiz_id' => $quizId],
        ]);
    }
}
