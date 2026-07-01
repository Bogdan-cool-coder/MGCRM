<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Services\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

/**
 * Regression tests for backend backlog fixes (2026-06-28).
 *
 * #9  (fix 1) PDF lesson player_src — streaming route + AssignmentDetailResource
 * #9  (fix 2) Reorder payload contract — confirmed consistent (documented)
 * #15 (fix 3) QuizAdminResource N+1 — 'lesson' eager-loaded in QuizService::list()
 * fix 4       Pass-gate strict — 79.5% must NOT pass an 80% gate
 * fix 5       AI-tutor authorization via Gate/Policy (no inline role check)
 */
class BacklogFixesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Fix 1 — PDF lesson player_src in AssignmentDetailResource
    // =========================================================================

    private function makePdfCourse(string $contentKey, string $contentValue): array
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'content' => [$contentKey => $contentValue],
            'is_published' => true,
        ]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        return [$student, $assignment, $lesson];
    }

    public function test_pdf_lesson_configured_by_path_returns_non_empty_player_src(): void
    {
        [$student, $assignment] = $this->makePdfCourse('path', 'onboarding/lessons/1/file.pdf');

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessons = $response->json('data.course.modules.0.lessons');
        $this->assertNotEmpty($lessons, 'Assignment must include lessons.');

        $pdfLesson = collect($lessons)->firstWhere('kind', 'pdf');
        $this->assertNotNull($pdfLesson, 'PDF lesson must appear in the response.');
        $this->assertNotNull($pdfLesson['player_src'], 'player_src must be non-null for a path-configured PDF lesson.');
        $this->assertStringContainsString('/api/onboarding/lessons/', $pdfLesson['player_src']);
        $this->assertStringContainsString('/pdf', $pdfLesson['player_src']);
    }

    public function test_pdf_lesson_configured_by_url_returns_non_empty_player_src(): void
    {
        [$student, $assignment] = $this->makePdfCourse('url', 'https://example.com/doc.pdf');

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessons = $response->json('data.course.modules.0.lessons');
        $pdfLesson = collect($lessons)->firstWhere('kind', 'pdf');
        $this->assertNotNull($pdfLesson, 'PDF lesson must appear in the response.');
        $this->assertNotNull($pdfLesson['player_src'], 'player_src must be non-null for a url-configured PDF lesson.');
        $this->assertStringContainsString('/api/onboarding/lessons/', $pdfLesson['player_src']);
        $this->assertStringContainsString('/pdf', $pdfLesson['player_src']);
    }

    public function test_non_pdf_lessons_have_null_player_src(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'content' => ['markdown' => '# Hello'],
            'is_published' => true,
        ]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessons = $response->json('data.course.modules.0.lessons');
        $textLesson = collect($lessons)->firstWhere('kind', 'text');
        $this->assertNotNull($textLesson);
        $this->assertNull($textLesson['player_src'], 'Non-PDF lessons must have null player_src.');
    }

    public function test_pdf_stream_route_streams_disk_pdf_for_assigned_student(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('onboarding/lessons/99/test.pdf', '%PDF-stub');

        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'content' => ['path' => 'onboarding/lessons/99/test.pdf'],
            'is_published' => true,
        ]);
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->get("/api/onboarding/lessons/{$lesson->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_stream_route_redirects_for_url_configured_pdf(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'content' => ['url' => 'https://example.com/guide.pdf'],
            'is_published' => true,
        ]);
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->get("/api/onboarding/lessons/{$lesson->id}/pdf")
            ->assertRedirect('https://example.com/guide.pdf');
    }

    public function test_pdf_stream_route_returns_403_for_unassigned_student(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'content' => ['url' => 'https://example.com/guide.pdf'],
            'is_published' => true,
        ]);
        // No assignment for $student

        Sanctum::actingAs($student, ['*']);

        $this->get("/api/onboarding/lessons/{$lesson->id}/pdf")
            ->assertForbidden();
    }

    public function test_pdf_stream_route_allows_admin_without_assignment(): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('onboarding/lessons/88/doc.pdf', '%PDF-stub');

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Pdf,
            'content' => ['path' => 'onboarding/lessons/88/doc.pdf'],
            'is_published' => true,
        ]);
        // No assignment for admin — should still be allowed.

        Sanctum::actingAs($admin, ['*']);

        $this->get("/api/onboarding/lessons/{$lesson->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_stream_route_returns_422_for_non_pdf_lesson(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'content' => ['markdown' => '# Hello'],
            'is_published' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->get("/api/onboarding/lessons/{$lesson->id}/pdf")
            ->assertUnprocessable();
    }

    // =========================================================================
    // Fix 3 — QuizAdminResource N+1: 'lesson' eager-loaded in QuizService::list()
    // =========================================================================

    public function test_quiz_index_does_not_lazy_load_lesson_per_row(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        // Create 3 quiz-lessons each with a quiz.
        for ($i = 0; $i < 3; $i++) {
            $lesson = Lesson::factory()->create([
                'module_id' => $module->id,
                'kind' => LessonKind::Quiz,
                'content' => ['quiz_id' => null, 'ai_generation_status' => 'idle'],
                'is_published' => false,
            ]);
            Quiz::factory()->create(['lesson_id' => $lesson->id]);
        }

        Sanctum::actingAs($admin, ['*']);

        // Count queries. The lesson relation must be in ONE batch query (not 3 per-row).
        $queryCount = 0;
        DB::listen(function ($q) use (&$queryCount): void {
            // Only track queries against the lessons table.
            if (str_contains((string) $q->sql, 'lessons')) {
                $queryCount++;
            }
        });

        $response = $this->getJson('/api/admin/onboarding/quizzes');
        $response->assertOk();

        // With eager loading: exactly 1 query against `lessons` for all 3 quizzes.
        // Without: 3 queries (N+1). Allow ≤2 for any framework overhead (hydration pass).
        $this->assertLessThanOrEqual(2, $queryCount,
            "Expected at most 2 lesson queries (eager-load batch), got {$queryCount} — N+1 not fixed.");
    }

    // =========================================================================
    // Fix 4 — Pass-gate strict: 79.5% does NOT pass an 80% gate
    // =========================================================================

    /**
     * Helper: build an unsaved QuizQuestion model with the given ID set on the
     * primary-key slot (not fillable, so we use setAttribute + forceFill pattern).
     *
     * @param  array<string,mixed>  $attrs
     */
    private function makeQuestion(int $id, int $points, array $optionIds): QuizQuestion
    {
        $q = new QuizQuestion(['text' => "Q{$id}", 'kind' => 'single_choice', 'explanation' => null, 'points' => $points]);
        // PK is guarded — set directly so computeScore can key the answer map.
        $q->setAttribute('id', $id);

        $options = collect($optionIds)->map(static fn (array $o): QuizOption => tap(
            new QuizOption(['text' => $o['text'], 'is_correct' => $o['is_correct'], 'sort_order' => $o['sort_order']]),
            static fn (QuizOption $opt) => $opt->setAttribute('id', $o['id']),
        ));

        $q->setRelation('options', $options);

        return $q;
    }

    public function test_pass_gate_strict_79_5_does_not_pass_80_pct_gate(): void
    {
        // Q1: 159 points — student answers CORRECTLY (earns 159)
        // Q2: 41  points — student answers INCORRECTLY (earns 0)
        // Raw ratio: 159 / 200 = 79.5%
        // round(79.5) = 80 in PHP (HALF_UP) → displayed as 80%.
        // Strict gate: 79.5 < 80 → NOT passed.
        $q1 = $this->makeQuestion(1, 159, [
            ['id' => 1, 'text' => 'Right', 'is_correct' => true,  'sort_order' => 1],
            ['id' => 2, 'text' => 'Wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);
        $q2 = $this->makeQuestion(2, 41, [
            ['id' => 3, 'text' => 'Right', 'is_correct' => true,  'sort_order' => 1],
            ['id' => 4, 'text' => 'Wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);

        $questions = collect([$q1, $q2]);
        $answers = [
            ['question_id' => 1, 'selected_option_ids' => [1]], // correct
            ['question_id' => 2, 'selected_option_ids' => [4]], // wrong
        ];

        $result = QuizService::computeScore($questions, $answers, passScorePct: 80);

        // Displayed score is rounded: round(79.5) = 80 in PHP (HALF_UP).
        $this->assertSame(80, $result['score_pct'], 'Displayed score_pct should be rounded to 80.');

        // Strict gate: 79.5 < 80 → not passed.
        $this->assertFalse($result['passed'], '79.5% raw ratio must NOT pass an 80% gate (strict comparison).');
    }

    public function test_pass_gate_strict_exactly_80_passes_80_pct_gate(): void
    {
        // Q1: 80 points correct, Q2: 20 points wrong → 80/100 = 80.0% exactly → must pass.
        $q1 = $this->makeQuestion(1, 80, [
            ['id' => 1, 'text' => 'Right', 'is_correct' => true,  'sort_order' => 1],
            ['id' => 2, 'text' => 'Wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);
        $q2 = $this->makeQuestion(2, 20, [
            ['id' => 3, 'text' => 'Right', 'is_correct' => true,  'sort_order' => 1],
            ['id' => 4, 'text' => 'Wrong', 'is_correct' => false, 'sort_order' => 2],
        ]);

        $questions = collect([$q1, $q2]);
        $answers = [
            ['question_id' => 1, 'selected_option_ids' => [1]], // correct
            ['question_id' => 2, 'selected_option_ids' => [4]], // wrong → 80/100
        ];

        $result = QuizService::computeScore($questions, $answers, passScorePct: 80);

        $this->assertSame(80, $result['score_pct']);
        $this->assertTrue($result['passed'], 'Exactly 80% must pass an 80% gate.');
    }

    public function test_pass_gate_zero_questions_returns_not_passed(): void
    {
        $result = QuizService::computeScore(collect([]), [], passScorePct: 80);

        $this->assertSame(0, $result['score_pct']);
        $this->assertFalse($result['passed'], 'Quiz with no questions must not pass.');
    }

    // =========================================================================
    // Fix 5 — AI-tutor authorization via Gate/Policy (no inline role check)
    // =========================================================================

    private function makeAiTutorLesson(): array
    {
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'content' => ['markdown' => 'Content.'],
            'is_published' => true,
        ]);

        return [$course, $lesson];
    }

    public function test_ai_tutor_admin_passes_via_policy_without_assignment(): void
    {
        Prism::fake([TextResponseFake::make()->withText('Answer.')]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        [, $lesson] = $this->makeAiTutorLesson();
        // No assignment for admin — Policy grants admin unconditionally.

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor", ['question' => 'What is this?'])
            ->assertOk();
    }

    public function test_ai_tutor_director_passes_via_policy_without_assignment(): void
    {
        Prism::fake([TextResponseFake::make()->withText('Answer.')]);

        $director = User::factory()->create(['role' => Role::Director]);
        [, $lesson] = $this->makeAiTutorLesson();

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor", ['question' => 'What is this?'])
            ->assertOk();
    }

    public function test_ai_tutor_assigned_student_passes(): void
    {
        Prism::fake([TextResponseFake::make()->withText('Answer.')]);

        $student = User::factory()->create(['role' => Role::Manager]);
        [$course, $lesson] = $this->makeAiTutorLesson();
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor", ['question' => 'What is this?'])
            ->assertOk();
    }

    public function test_ai_tutor_unassigned_student_gets_403(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        [, $lesson] = $this->makeAiTutorLesson();
        // No assignment.

        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor", ['question' => 'What is this?'])
            ->assertForbidden();
    }

    public function test_ai_tutor_history_admin_passes_via_policy(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        [, $lesson] = $this->makeAiTutorLesson();

        Sanctum::actingAs($admin, ['*']);

        // History returns empty array — just verify 200, not 403.
        $this->getJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor/history")
            ->assertOk();
    }

    public function test_ai_tutor_history_unassigned_student_gets_403(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        [, $lesson] = $this->makeAiTutorLesson();

        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/onboarding/lessons/{$lesson->id}/ai-tutor/history")
            ->assertForbidden();
    }
}
