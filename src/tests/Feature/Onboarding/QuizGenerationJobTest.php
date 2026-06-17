<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Services\LessonService;
use App\Domain\Onboarding\Services\QuizGenerationService;
use App\Jobs\Onboarding\GenerateQuizQuestionsJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature tests for GenerateQuizQuestionsJob.
 *
 * AI calls isolated via Prism::fake or QuizGenerationService binding.
 */
class QuizGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $textLesson;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $module = CourseModule::factory()->create();
        $this->textLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => 'text',
            'content' => ['markdown' => 'Это учебный материал для генерации вопросов.'],
        ]);
        // Quiz needs a lesson_id (S3.2: 1:1 with lesson.kind=quiz normally,
        // but for the generation tests we bind the quiz directly to the text lesson).
        $this->quiz = Quiz::factory()->make(['lesson_id' => $this->textLesson->id]);
        $this->quiz->save();
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_job_sets_status_running_then_done(): void
    {
        $fakeJson = json_encode([
            'questions' => [
                [
                    'text' => 'Что такое онбординг?',
                    'kind' => 'single_choice',
                    'explanation' => 'Адаптация нового сотрудника.',
                    'options' => [
                        ['text' => 'Процесс адаптации', 'is_correct' => true],
                        ['text' => 'Система учёта', 'is_correct' => false],
                    ],
                ],
            ],
        ]);

        Prism::fake([
            TextResponseFake::make()->withText("```json\n{$fakeJson}\n```"),
        ]);

        $job = new GenerateQuizQuestionsJob($this->textLesson->id, $this->quiz->id, 1);
        $job->handle(
            $this->app->make(QuizGenerationService::class),
            $this->app->make(LessonService::class),
        );

        $this->textLesson->refresh();

        $this->assertSame('done', data_get($this->textLesson->content, 'ai_generation_status'));
    }

    public function test_job_creates_draft_questions_with_options(): void
    {
        $fakeJson = json_encode([
            'questions' => [
                [
                    'text' => 'Первый вопрос?',
                    'kind' => 'single_choice',
                    'explanation' => 'Объяснение.',
                    'options' => [
                        ['text' => 'Правильный', 'is_correct' => true],
                        ['text' => 'Неправильный A', 'is_correct' => false],
                        ['text' => 'Неправильный Б', 'is_correct' => false],
                    ],
                ],
                [
                    'text' => 'Второй вопрос?',
                    'kind' => 'multiple_choice',
                    'explanation' => 'Объяснение 2.',
                    'options' => [
                        ['text' => 'Правильный A', 'is_correct' => true],
                        ['text' => 'Правильный Б', 'is_correct' => true],
                        ['text' => 'Неправильный', 'is_correct' => false],
                    ],
                ],
            ],
        ]);

        Prism::fake([
            TextResponseFake::make()->withText("```json\n{$fakeJson}\n```"),
        ]);

        $job = new GenerateQuizQuestionsJob($this->textLesson->id, $this->quiz->id, 2);
        $job->handle(
            $this->app->make(QuizGenerationService::class),
            $this->app->make(LessonService::class),
        );

        $questions = QuizQuestion::where('quiz_id', $this->quiz->id)->get();

        $this->assertCount(2, $questions);

        foreach ($questions as $q) {
            $this->assertTrue($q->is_draft, 'Question should be a draft');
            $this->assertGreaterThanOrEqual(2, $q->options()->count(), 'Question should have options');
        }
    }

    // =========================================================================
    // Failure path
    // =========================================================================

    public function test_job_sets_status_failed_on_ai_error(): void
    {
        $this->app->bind(QuizGenerationService::class, function () {
            return new class extends QuizGenerationService
            {
                public function __construct()
                {
                    // Skip injection
                }

                public function generate(Lesson $lesson, Quiz $quiz, int $desiredCount = 5): array
                {
                    throw new RuntimeException('Anthropic API unreachable');
                }
            };
        });

        $job = new GenerateQuizQuestionsJob($this->textLesson->id, $this->quiz->id);
        $job->handle(
            $this->app->make(QuizGenerationService::class),
            $this->app->make(LessonService::class),
        );

        $this->textLesson->refresh();

        $this->assertSame('failed', data_get($this->textLesson->content, 'ai_generation_status'));
        $this->assertStringContainsString(
            'Anthropic API unreachable',
            (string) data_get($this->textLesson->content, 'ai_generation_error')
        );
    }

    public function test_job_missing_lesson_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $job = new GenerateQuizQuestionsJob(99999, $this->quiz->id);
        $job->handle(
            $this->app->make(QuizGenerationService::class),
            $this->app->make(LessonService::class),
        );
    }
}
