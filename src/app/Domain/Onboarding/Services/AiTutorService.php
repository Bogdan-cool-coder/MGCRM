<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\OnboardingAiSession;
use App\Support\Ai\AiRetryService;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * AiTutorService — multi-turn AI-тьютор по контенту урока.
 *
 * Business rules:
 * - One OnboardingAiSession per (user_id, lesson_id) — upsert on each ask.
 * - History: last 10 pairs (20 messages) kept. Older messages are dropped.
 * - Lesson context: built from Lesson.content, max 30 000 chars.
 * - AI call: AiRetryService::executeWithRetry('tutor', ...) — Sonnet cascade.
 * - On AI failure: throws Throwable (controller maps to 503).
 */
class AiTutorService
{
    public function __construct(
        private readonly AiRetryService $aiRetryService,
    ) {}

    /**
     * Ask the AI tutor a question about the lesson.
     *
     * @return array{answer: string, session_id: int}
     *
     * @throws \Throwable on AI failure (controller converts to 503)
     */
    public function ask(User $user, Lesson $lesson, string $question): array
    {
        // Load or create session for this (user, lesson) pair.
        $session = OnboardingAiSession::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        if (! $session->exists) {
            $session->messages = [];
            $session->save();
        }

        // Build system prompt from file.
        $systemPrompt = $this->loadSystemPrompt();

        // Build lesson context string.
        $lessonContext = $this->buildLessonContext($lesson);

        // Build Prism message history from stored messages.
        $messages = [];
        foreach ($session->messages as $msg) {
            if ($msg['role'] === 'user') {
                // Prepend lesson context to the first user message in history only.
                $messages[] = new UserMessage($msg['content']);
            } else {
                $messages[] = new AssistantMessage($msg['content']);
            }
        }

        // Append the new user question with full lesson context embedded.
        $questionWithContext = $lessonContext."\n\n## Вопрос студента:\n\n".$question;
        $messages[] = new UserMessage($questionWithContext);

        // Call AI — throws on failure.
        $response = $this->aiRetryService->executeWithRetry('tutor', $systemPrompt, $messages);

        $answerText = $response->text;
        $now = now()->toISOString();

        // Append to session messages (store plain question without context for history).
        $stored = $session->messages ?? [];
        $stored[] = ['role' => 'user', 'content' => $question, 'created_at' => $now];
        $stored[] = ['role' => 'assistant', 'content' => $answerText, 'created_at' => $now];

        // Truncate to last 10 pairs = 20 messages.
        if (count($stored) > 20) {
            $stored = array_slice($stored, -20);
        }

        $session->messages = $stored;
        $session->save();

        return [
            'answer' => $answerText,
            'session_id' => $session->id,
        ];
    }

    /**
     * Return the stored message history for this (user, lesson) pair.
     *
     * @return list<array{role: string, content: string, created_at: string}>
     */
    public function getHistory(User $user, Lesson $lesson): array
    {
        $session = OnboardingAiSession::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        return $session?->messages ?? [];
    }

    /**
     * Clear the message history for this (user, lesson) pair.
     */
    public function clearHistory(User $user, Lesson $lesson): void
    {
        OnboardingAiSession::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->update(['messages' => '[]']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function loadSystemPrompt(): string
    {
        $path = base_path('TUTOR_SYSTEM_PROMPT.md');

        if (! file_exists($path)) {
            throw new \RuntimeException('TUTOR_SYSTEM_PROMPT.md not found at '.$path.'. Deploy is broken.');
        }

        return (string) file_get_contents($path);
    }

    /**
     * Build lesson context string for embedding in the user message.
     */
    private function buildLessonContext(Lesson $lesson): string
    {
        $content = $lesson->content ?? [];
        $prefix = "## Контент урока: {$lesson->title}\n\n";

        $text = match ($lesson->kind) {
            LessonKind::Text => (string) ($content['markdown'] ?? $content['body'] ?? json_encode($content)),
            LessonKind::Pdf => (string) ($content['text_preview'] ?? "PDF-файл: {$lesson->title}"),
            default => json_encode($content) ?: '',
        };

        $maxLength = 30_000;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength).' [TRUNCATED]';
        }

        return $prefix.$text;
    }
}
