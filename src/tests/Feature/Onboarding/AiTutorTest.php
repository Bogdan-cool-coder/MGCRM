<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\OnboardingAiSession;
use App\Domain\Onboarding\Models\Quiz;
use App\Jobs\Onboarding\GenerateQuizQuestionsJob;
use App\Support\Ai\AiRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature tests for S3.5: AI-тьютор + trigger for quiz generation.
 *
 * All AI calls are intercepted via Prism::fake or AiRetryService binding.
 * No real Anthropic calls.
 */
class AiTutorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private CourseModule $module;

    private Lesson $lesson;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->student = User::factory()->create(['role' => Role::Manager]);

        $this->course = Course::factory()->create(['is_published' => true]);
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->lesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'text',
            'content' => ['markdown' => 'Это учебный материал о процессе онбординга сотрудников.'],
            'is_published' => true,
        ]);
        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::InProgress,
        ]);
    }

    // =========================================================================
    // AI-тьютор — ask
    // =========================================================================

    public function test_student_can_ask_tutor_question(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Онбординг — это процесс адаптации нового сотрудника в компании.'),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Что такое онбординг?']
        );

        $response->assertOk()
            ->assertJsonStructure(['data' => ['answer', 'session_id']])
            ->assertJsonPath('data.answer', 'Онбординг — это процесс адаптации нового сотрудника в компании.');
    }

    public function test_tutor_stores_session_messages(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Ответ AI на первый вопрос.'),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Первый вопрос?']
        )->assertOk();

        $session = OnboardingAiSession::where('user_id', $this->student->id)
            ->where('lesson_id', $this->lesson->id)
            ->first();

        $this->assertNotNull($session);
        $this->assertCount(2, $session->messages);
        $this->assertSame('user', $session->messages[0]['role']);
        $this->assertSame('Первый вопрос?', $session->messages[0]['content']);
        $this->assertSame('assistant', $session->messages[1]['role']);
        $this->assertSame('Ответ AI на первый вопрос.', $session->messages[1]['content']);
    }

    public function test_tutor_appends_messages_on_second_ask(): void
    {
        Prism::fake([
            TextResponseFake::make()->withText('Первый ответ.'),
            TextResponseFake::make()->withText('Второй ответ.'),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Вопрос 1?']
        )->assertOk();

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Вопрос 2?']
        )->assertOk();

        $session = OnboardingAiSession::where('user_id', $this->student->id)
            ->where('lesson_id', $this->lesson->id)
            ->first();

        $this->assertNotNull($session);
        $this->assertCount(4, $session->messages);
    }

    public function test_tutor_truncates_to_last_10_pairs(): void
    {
        // Pre-fill session with 10 pairs (20 messages).
        $session = OnboardingAiSession::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'messages' => $this->buildMessages(10),
        ]);

        Prism::fake([
            TextResponseFake::make()->withText('Ответ на 11-й вопрос.'),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Вопрос 11?']
        )->assertOk();

        $session->refresh();

        // After adding pair #11 and truncating: still 20 messages (10 pairs).
        $this->assertCount(20, $session->messages);

        // The oldest pair (#1) should be gone; the last user message is #11.
        $lastUserMessages = array_filter($session->messages, fn ($m) => $m['role'] === 'user');
        $lastUser = end($lastUserMessages);
        $this->assertSame('Вопрос 11?', $lastUser['content']);
    }

    public function test_tutor_returns_history(): void
    {
        OnboardingAiSession::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'messages' => $this->buildMessages(2),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->getJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        );

        $response->assertOk()
            ->assertJsonCount(4); // 2 pairs × 2
    }

    public function test_tutor_returns_empty_history_when_no_session(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $response = $this->getJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        );

        $response->assertOk()->assertExactJson([]);
    }

    public function test_clear_history_empties_messages(): void
    {
        OnboardingAiSession::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'messages' => $this->buildMessages(3),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->deleteJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor/history"
        )->assertNoContent();

        $session = OnboardingAiSession::where('user_id', $this->student->id)
            ->where('lesson_id', $this->lesson->id)
            ->first();

        $this->assertNotNull($session);
        $this->assertEmpty($session->messages);
    }

    public function test_tutor_returns_503_on_ai_failure(): void
    {
        $this->app->bind(AiRetryService::class, function () {
            return new class extends AiRetryService
            {
                public function __construct()
                {
                    // Skip injection
                }

                public function executeWithRetry(string $chatType, string $systemPrompt, array $messages, array $tools = []): never
                {
                    throw new RuntimeException('Anthropic API unreachable');
                }
            };
        });

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Вопрос при ошибке?']
        );

        $response->assertStatus(503)
            ->assertJsonPath('error', 'AI-тьютор временно недоступен. Попробуйте позже.');
    }

    public function test_unauthenticated_cannot_ask_tutor(): void
    {
        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->lesson->id}/ai-tutor",
            ['question' => 'Вопрос?']
        );

        $response->assertUnauthorized();
    }

    // =========================================================================
    // Generate questions — dispatch trigger
    // =========================================================================

    public function test_admin_can_generate_questions_for_text_lesson(): void
    {
        Queue::fake();

        $quiz = Quiz::factory()->create(['lesson_id' => $this->lesson->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$this->lesson->id}/generate-questions",
            ['desired_count' => 5]
        );

        $response->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(GenerateQuizQuestionsJob::class);

        // Status should be 'pending' in lesson content.
        $this->lesson->refresh();
        $this->assertSame('pending', data_get($this->lesson->content, 'ai_generation_status'));
    }

    public function test_generate_returns_422_for_video_lesson(): void
    {
        Queue::fake();

        $videoLesson = Lesson::factory()->video()->create(['module_id' => $this->module->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$videoLesson->id}/generate-questions"
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Генерация доступна только для текстовых и PDF-уроков.');

        Queue::assertNothingPushed();
    }

    public function test_generate_returns_422_for_quiz_lesson(): void
    {
        Queue::fake();

        $quizLesson = Lesson::factory()->quiz()->create(['module_id' => $this->module->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$quizLesson->id}/generate-questions"
        );

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_generate_returns_422_if_no_quiz_attached(): void
    {
        Queue::fake();

        // lesson exists but no Quiz record for it.
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$this->lesson->id}/generate-questions"
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Сначала создайте квиз для урока через раздел «Квизы».');

        Queue::assertNothingPushed();
    }

    public function test_generate_returns_409_if_already_running(): void
    {
        Queue::fake();

        $quiz = Quiz::factory()->create(['lesson_id' => $this->lesson->id]);

        // Set status to running.
        $content = $this->lesson->content ?? [];
        $content['ai_generation_status'] = 'running';
        $this->lesson->update(['content' => $content]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$this->lesson->id}/generate-questions"
        );

        $response->assertStatus(409)
            ->assertJsonPath('error', 'Генерация уже запущена. Дождитесь завершения или проверьте статус.');

        Queue::assertNothingPushed();
    }

    public function test_student_cannot_generate_questions(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/lessons/{$this->lesson->id}/generate-questions"
        );

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build $n pairs of user/assistant messages.
     *
     * @return list<array{role: string, content: string, created_at: string}>
     */
    private function buildMessages(int $pairs): array
    {
        $messages = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $messages[] = ['role' => 'user', 'content' => "Вопрос {$i}?", 'created_at' => now()->toISOString()];
            $messages[] = ['role' => 'assistant', 'content' => "Ответ {$i}.", 'created_at' => now()->toISOString()];
        }

        return $messages;
    }
}
