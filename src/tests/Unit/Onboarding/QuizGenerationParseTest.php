<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Services\QuizGenerationService;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit tests for QuizGenerationService::parseAiResponse.
 *
 * No DB, no AI calls — pure string parsing.
 */
class QuizGenerationParseTest extends TestCase
{
    private QuizGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Partial mock — we only test parseAiResponse which is public.
        // We instantiate with mocked dependencies (not used in parseAiResponse).
        $this->service = $this->app->make(QuizGenerationService::class);
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    public function test_parses_valid_json_with_questions(): void
    {
        $json = json_encode([
            'questions' => [
                [
                    'text' => 'Что такое онбординг?',
                    'kind' => 'single_choice',
                    'explanation' => 'Онбординг — процесс адаптации нового сотрудника.',
                    'options' => [
                        ['text' => 'Процесс адаптации', 'is_correct' => true],
                        ['text' => 'Система учёта', 'is_correct' => false],
                    ],
                ],
            ],
        ]);

        $result = $this->service->parseAiResponse((string) $json);

        $this->assertCount(1, $result);
        $this->assertSame('Что такое онбординг?', $result[0]['text']);
        $this->assertSame('single_choice', $result[0]['kind']);
        $this->assertCount(2, $result[0]['options']);
    }

    public function test_parses_json_wrapped_in_backticks(): void
    {
        $inner = json_encode([
            'questions' => [
                ['text' => 'Q1', 'kind' => 'single_choice', 'options' => []],
            ],
        ]);

        $raw = "```json\n{$inner}\n```";

        $result = $this->service->parseAiResponse($raw);

        $this->assertCount(1, $result);
        $this->assertSame('Q1', $result[0]['text']);
    }

    public function test_parses_json_wrapped_in_backticks_without_language(): void
    {
        $inner = json_encode(['questions' => [['text' => 'Q2', 'kind' => 'multiple_choice', 'options' => []]]]);
        $raw = "```\n{$inner}\n```";

        $result = $this->service->parseAiResponse($raw);

        $this->assertCount(1, $result);
    }

    public function test_returns_empty_array_on_empty_questions_key(): void
    {
        $raw = json_encode(['questions' => []]);

        $result = $this->service->parseAiResponse((string) $raw);

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_questions_key_missing(): void
    {
        $raw = json_encode(['other' => 'data']);

        $result = $this->service->parseAiResponse((string) $raw);

        $this->assertSame([], $result);
    }

    public function test_handles_missing_explanation_field(): void
    {
        $raw = json_encode([
            'questions' => [
                ['text' => 'Q', 'kind' => 'single_choice', 'options' => []],
            ],
        ]);

        $result = $this->service->parseAiResponse((string) $raw);

        $this->assertArrayNotHasKey('explanation', $result[0]);
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function test_throws_on_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');

        $this->service->parseAiResponse('this is not json {{{');
    }

    public function test_throws_on_truncated_json(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->parseAiResponse('{"questions": [{"text": "incomplete"');
    }
}
