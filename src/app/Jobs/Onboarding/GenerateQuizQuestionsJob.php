<?php

declare(strict_types=1);

namespace App\Jobs\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Services\LessonService;
use App\Domain\Onboarding\Services\QuizGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateQuizQuestionsJob — async AI quiz question generation.
 *
 * Pattern 1-for-1 with CheckTemplateJob:
 * - tries=1: AiRetryService already handles cascade retries internally.
 * - timeout=600: allows full Sonnet cascade to complete.
 * - onQueue('default').
 *
 * Status flow: pending → running → done|failed (stored in Lesson.content JSONB).
 * On failure: status=failed + error message; NOT re-thrown (no failed-queue noise).
 * On Lesson/Quiz not found: findOrFail propagates (expected rare case — job fails normally).
 */
class GenerateQuizQuestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Queue worker SIGTERM timeout (seconds) */
    public int $timeout = 600;

    /** @var int One attempt only — cascade handles retries internally */
    public int $tries = 1;

    public function __construct(
        private readonly int $lessonId,
        private readonly int $quizId,
        private readonly int $desiredCount = 5,
    ) {
        $this->onQueue('default');
    }

    public function handle(QuizGenerationService $service, LessonService $lessonService): void
    {
        $lesson = Lesson::findOrFail($this->lessonId);
        $quiz = Quiz::findOrFail($this->quizId);

        $lessonService->setAiGenerationStatus($lesson, 'running');

        try {
            $result = $service->generate($lesson, $quiz, $this->desiredCount);

            $lessonService->setAiGenerationStatus($lesson, 'done');

            Log::info('GenerateQuizQuestionsJob done', [
                'lesson_id' => $this->lessonId,
                'quiz_id' => $this->quizId,
                'questions_created' => $result['questions_created'],
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateQuizQuestionsJob failed', [
                'lesson_id' => $this->lessonId,
                'quiz_id' => $this->quizId,
                'error' => $e->getMessage(),
            ]);

            $lessonService->setAiGenerationStatus($lesson, 'failed', $e->getMessage());

            // Intentionally NOT re-throwing — status=failed is sufficient signal.
            // Admin can re-dispatch via the endpoint; no failed-queue noise needed.
        }
    }
}
