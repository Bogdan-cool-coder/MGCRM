<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Services\AI\AiRetryService;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * QuizGenerationService — generate draft quiz questions from lesson content.
 *
 * Business rules:
 * - Only kind=text and kind=pdf lessons are supported (others → RuntimeException).
 * - Questions are saved as is_draft=true — HR reviews and publishes.
 * - Answers stored via QuizOptionService::create().
 * - AI response must be valid JSON with a 'questions' key.
 * - parseAiResponse throws RuntimeException on invalid JSON (Job catches → status=failed).
 */
class QuizGenerationService
{
    public function __construct(
        private readonly AiRetryService $aiRetryService,
        private readonly QuizQuestionService $questionService,
        private readonly QuizOptionService $optionService,
    ) {}

    /**
     * Generate draft quiz questions for a lesson.
     *
     * @return array{questions_created: int}
     *
     * @throws \RuntimeException on unsupported kind, invalid AI response
     */
    public function generate(Lesson $lesson, Quiz $quiz, int $desiredCount = 5): array
    {
        if (! in_array($lesson->kind, [LessonKind::Text, LessonKind::Pdf], strict: true)) {
            throw new \RuntimeException(
                'Quiz generation is only available for text and PDF lessons. Got: '.$lesson->kind->value
            );
        }

        $systemPrompt = $this->loadSystemPrompt();
        $lessonContext = $this->buildLessonContext($lesson);

        $userContent = "## Контент урока:\n\n{$lessonContext}\n\n"
            ."## Квиз:\n"
            ."Название: {$quiz->title}\n"
            ."Проходной балл: {$quiz->pass_score_pct}%\n"
            ."Желаемое количество вопросов: {$desiredCount}";

        $response = $this->aiRetryService->executeWithRetry(
            'quiz_generation',
            $systemPrompt,
            [new UserMessage($userContent)],
        );

        $parsed = $this->parseAiResponse($response->text);

        $created = 0;
        foreach ($parsed as $q) {
            $question = $this->questionService->create($quiz, [
                'text' => (string) ($q['text'] ?? ''),
                'kind' => (string) ($q['kind'] ?? 'single_choice'),
                'explanation' => isset($q['explanation']) ? (string) $q['explanation'] : null,
                'points' => 1,
                'is_draft' => true,
            ]);

            foreach ($q['options'] ?? [] as $opt) {
                $this->optionService->create($question, [
                    'text' => (string) ($opt['text'] ?? ''),
                    'is_correct' => (bool) ($opt['is_correct'] ?? false),
                ]);
            }

            $created++;
        }

        return ['questions_created' => $created];
    }

    /**
     * Parse AI response JSON — pattern from TemplateCheckService::parseAiResponse.
     *
     * Strips markdown code fences, decodes JSON, extracts 'questions' key.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException on invalid JSON
     */
    public function parseAiResponse(string $raw): array
    {
        // Strip ```json ... ``` or ``` ... ``` wrappers.
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $cleaned = preg_replace('/\s*```\s*$/m', '', (string) $cleaned);
        $cleaned = trim((string) $cleaned);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'QuizGenerationService: invalid JSON from AI: '.$e->getMessage().'. Raw: '.mb_substr($raw, 0, 500)
            );
        }

        /** @var list<array<string, mixed>> $questions */
        $questions = $decoded['questions'] ?? [];

        if (! is_array($questions)) {
            return [];
        }

        return $questions;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function loadSystemPrompt(): string
    {
        $path = base_path('QUIZ_GEN_SYSTEM_PROMPT.md');

        if (! file_exists($path)) {
            throw new \RuntimeException('QUIZ_GEN_SYSTEM_PROMPT.md not found at '.$path.'. Deploy is broken.');
        }

        return (string) file_get_contents($path);
    }

    private function buildLessonContext(Lesson $lesson): string
    {
        $content = $lesson->content ?? [];

        $text = match ($lesson->kind) {
            LessonKind::Text => (string) ($content['markdown'] ?? $content['body'] ?? json_encode($content)),
            LessonKind::Pdf => (string) ($content['text_preview'] ?? "PDF-файл: {$lesson->title}"),
            default => '',
        };

        $maxLength = 30_000;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength).' [TRUNCATED]';
        }

        return $text;
    }
}
