<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Services\AiTutorService;
use App\Services\AI\AiRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Unit tests for AiTutorService context building.
 *
 * We test the private buildLessonContext method indirectly by calling ask()
 * with a mocked AiRetryService and inspecting what message was passed.
 *
 * These tests do NOT touch the DB (no RefreshDatabase) — Lesson is created
 * as a plain object mock.
 */
class AiTutorBuildContextTest extends TestCase
{
    use RefreshDatabase;

    private AiTutorService $service;

    /** @var MockInterface&AiRetryService */
    private $aiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiMock = Mockery::mock(AiRetryService::class);

        $this->service = new AiTutorService($this->aiMock);
    }

    public function test_builds_context_for_text_lesson(): void
    {
        $user = User::factory()->create();
        $module = CourseModule::factory()->create();
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'title' => 'Тест урока',
            'content' => ['markdown' => 'Это **текст** урока о продукте.'],
        ]);

        $capturedMessages = null;

        $this->aiMock
            ->shouldReceive('executeWithRetry')
            ->once()
            ->with(
                'tutor',
                Mockery::type('string'),
                Mockery::on(function (array $messages) use (&$capturedMessages): bool {
                    $capturedMessages = $messages;

                    return true;
                }),
            )
            ->andReturn($this->makePrismResponse('Ответ тьютора'));

        $this->service->ask($user, $lesson, 'Что такое продукт?');

        // The last message in the array should contain the lesson context.
        $this->assertNotNull($capturedMessages);
        $lastMsg = end($capturedMessages);
        $this->assertInstanceOf(UserMessage::class, $lastMsg);
        $this->assertStringContainsString('Тест урока', $lastMsg->content);
        $this->assertStringContainsString('Это **текст** урока о продукте.', $lastMsg->content);
    }

    public function test_truncates_long_content_at_30k(): void
    {
        $user = User::factory()->create();
        $module = CourseModule::factory()->create();
        $longContent = str_repeat('А', 35_000);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'title' => 'Длинный урок',
            'content' => ['markdown' => $longContent],
        ]);

        $capturedMessages = null;

        $this->aiMock
            ->shouldReceive('executeWithRetry')
            ->once()
            ->with('tutor', Mockery::any(), Mockery::on(function (array $messages) use (&$capturedMessages): bool {
                $capturedMessages = $messages;

                return true;
            }))
            ->andReturn($this->makePrismResponse('Ответ'));

        $this->service->ask($user, $lesson, 'Вопрос?');

        $lastMsg = end($capturedMessages);
        $content = $lastMsg->content;

        // Must contain TRUNCATED marker.
        $this->assertStringContainsString('[TRUNCATED]', $content);

        // The actual content should not exceed 30k + overhead (title, prefix, question section).
        $this->assertLessThanOrEqual(30_000 + 500, mb_strlen($content));
    }

    public function test_builds_context_for_pdf_lesson(): void
    {
        $user = User::factory()->create();
        $module = CourseModule::factory()->create();
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'title' => 'PDF урок',
            'content' => ['path' => 'onboarding/lessons/1/doc.pdf'],
        ]);

        $capturedMessages = null;

        $this->aiMock
            ->shouldReceive('executeWithRetry')
            ->once()
            ->andReturn($this->makePrismResponse('Ответ PDF'))
            ->with('tutor', Mockery::any(), Mockery::on(function (array $msgs) use (&$capturedMessages): bool {
                $capturedMessages = $msgs;

                return true;
            }));

        $this->service->ask($user, $lesson, 'О чём PDF?');

        $lastMsg = end($capturedMessages);
        $content = $lastMsg->content;

        // PDF without text_preview falls back to filename.
        $this->assertStringContainsString('PDF урок', $content);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePrismResponse(string $text): Response
    {
        return new Response(
            steps: collect([]),
            text: $text,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('', ''),
            messages: collect([]),
            additionalContent: [],
        );
    }
}
