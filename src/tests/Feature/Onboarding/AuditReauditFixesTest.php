<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Enums\CompletionPolicy;
use App\Domain\Onboarding\Enums\LessonKind;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for control re-audit fixes (onboarding domain).
 *
 * Covers:
 * [MAJOR]  Quiz review screen — annotated answers must inline question_text,
 *          explanation, correct_option_ids, correct_option_texts.
 * [MAJOR]  SoftGate server-side enforcement in ProgressService::recordLessonDone.
 * [MAJOR]  AssignmentStatus::Failed reachable via ProgressService::markFailed.
 * [MINOR]  CourseAssigned event intentionally has no listener (extension point).
 * [MINOR]  HR dashboard uses bulk queries (no per-row N+1).
 */
class AuditReauditFixesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Quiz review — annotated answer shape
    // =========================================================================

    public function test_quiz_submit_result_includes_question_text_in_each_answer(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $quizLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Quiz,
            'is_published' => true,
        ]);
        $quiz = Quiz::factory()->create([
            'lesson_id' => $quizLesson->id,
            'pass_score_pct' => 60,
        ]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'What is the main feature of MACRO CRM?',
            'explanation' => 'MACRO CRM manages client relationships.',
            'points' => 1,
            'is_draft' => false,
        ]);
        $correctOption = QuizOption::factory()->create([
            'question_id' => $question->id,
            'text' => 'Client relationship management',
            'is_correct' => true,
        ]);
        QuizOption::factory()->create([
            'question_id' => $question->id,
            'text' => 'Document scanning',
            'is_correct' => false,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
            'attempt_number' => 1,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson("/api/onboarding/quiz-attempts/{$attempt->id}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ])->assertOk();

        $answer = $response->json('data.answers.0');

        // Must contain resolved text fields — not bare IDs
        $this->assertSame('What is the main feature of MACRO CRM?', $answer['question_text'],
            'annotated answer must include question_text');
        $this->assertSame('MACRO CRM manages client relationships.', $answer['explanation'],
            'annotated answer must include explanation');
        $this->assertSame([$correctOption->id], $answer['correct_option_ids'],
            'annotated answer must include correct_option_ids');
        $this->assertSame(['Client relationship management'], $answer['correct_option_texts'],
            'annotated answer must include correct_option_texts (human-readable)');
        $this->assertTrue($answer['is_correct']);

        // question_details[] should NOT be in the response (removed — all inlined)
        $this->assertArrayNotHasKey('question_details', $response->json('data'),
            'question_details was removed; all fields are now inlined in answers[]');
    }

    public function test_quiz_result_resource_shape_for_incorrect_answer(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $quizLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Quiz,
            'is_published' => true,
        ]);
        $quiz = Quiz::factory()->create([
            'lesson_id' => $quizLesson->id,
            'pass_score_pct' => 100,
        ]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Capital of France?',
            'explanation' => 'Paris is the capital city.',
            'points' => 1,
            'is_draft' => false,
        ]);
        $correctOption = QuizOption::factory()->create([
            'question_id' => $question->id,
            'text' => 'Paris',
            'is_correct' => true,
        ]);
        $wrongOption = QuizOption::factory()->create([
            'question_id' => $question->id,
            'text' => 'Lyon',
            'is_correct' => false,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
            'attempt_number' => 1,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson("/api/onboarding/quiz-attempts/{$attempt->id}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'selected_option_ids' => [$wrongOption->id]],
            ],
        ])->assertOk();

        $answer = $response->json('data.answers.0');

        $this->assertFalse($answer['is_correct']);
        $this->assertSame('Capital of France?', $answer['question_text']);
        $this->assertSame('Paris is the capital city.', $answer['explanation']);
        $this->assertSame(['Paris'], $answer['correct_option_texts'],
            'correct_option_texts allows review screen to show text, not bare IDs');
        $this->assertSame([$correctOption->id], $answer['correct_option_ids']);
        $this->assertSame([$wrongOption->id], $answer['selected_option_ids']);
    }

    // =========================================================================
    // SoftGate server-side enforcement
    // =========================================================================

    public function test_soft_gate_course_blocks_completing_lesson_out_of_order(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create([
            'is_published' => true,
            'completion_policy' => CompletionPolicy::SoftGate,
        ]);
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        // Two lessons in order: lesson1 (sort_order 1), lesson2 (sort_order 2)
        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 1,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 2,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Pending,
        ]);

        Sanctum::actingAs($student, ['*']);

        // Attempting to complete lesson2 before lesson1 → 422
        $response = $this->postJson("/api/onboarding/lessons/{$lesson2->id}/complete", [
            'time_spent_seconds' => 30,
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'prior lessons',
            strtolower($response->json('message')),
            'SoftGate must reject out-of-order completion with a clear message.'
        );
    }

    public function test_soft_gate_course_allows_completing_lesson_in_order(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create([
            'is_published' => true,
            'completion_policy' => CompletionPolicy::SoftGate,
        ]);
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 1,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 2,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Pending,
        ]);

        Sanctum::actingAs($student, ['*']);

        // Complete lesson1 first → OK (first lesson, no prior)
        $this->postJson("/api/onboarding/lessons/{$lesson1->id}/complete", [
            'time_spent_seconds' => 30,
        ])->assertStatus(201);

        // Now complete lesson2 → also OK (gate allows, lesson1 done)
        $this->postJson("/api/onboarding/lessons/{$lesson2->id}/complete", [
            'time_spent_seconds' => 30,
        ])->assertStatus(201);
    }

    public function test_informational_course_allows_completing_lesson_out_of_order(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create([
            'is_published' => true,
            'completion_policy' => CompletionPolicy::Informational,
        ]);
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'sort_order' => 1]);

        Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 1,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 2,
        ]);

        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        // Informational: can skip to lesson2 without completing lesson1
        $this->postJson("/api/onboarding/lessons/{$lesson2->id}/complete", [
            'time_spent_seconds' => 30,
        ])->assertStatus(201);
    }

    public function test_soft_gate_first_lesson_always_allowed(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create([
            'is_published' => true,
            'completion_policy' => CompletionPolicy::SoftGate,
        ]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'sort_order' => 1,
        ]);

        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        // First lesson — no prior required, always allowed
        $this->postJson("/api/onboarding/lessons/{$lesson1->id}/complete")
            ->assertStatus(201);
    }

    // =========================================================================
    // AssignmentStatus::Failed reachable via ProgressService::markFailed
    // =========================================================================

    public function test_mark_failed_sets_status_to_failed(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        $service = app(ProgressService::class);
        $service->markFailed($assignment);
        $assignment->refresh();

        $this->assertSame(AssignmentStatus::Failed, $assignment->status);
    }

    public function test_mark_failed_is_idempotent_if_already_failed(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Failed,
        ]);

        $service = app(ProgressService::class);
        $service->markFailed($assignment); // should not throw

        $this->assertSame(AssignmentStatus::Failed, $assignment->fresh()->status);
    }

    public function test_mark_failed_no_op_if_already_completed(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Completed,
            'completed_at' => now(),
        ]);

        $service = app(ProgressService::class);
        $service->markFailed($assignment); // must not change status

        $this->assertSame(AssignmentStatus::Completed, $assignment->fresh()->status);
    }

    // =========================================================================
    // CourseAssigned event — intentionally no listener (M11 extension point)
    // =========================================================================

    public function test_course_assigned_event_dispatches_without_listener_no_exception(): void
    {
        // The event has no listener registered (by design, M11). Dispatching it
        // must not throw or cause 500 errors.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        // bulkAssign dispatches CourseAssigned for each new assignment
        $response = $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$learner->id],
        ]);

        // Must succeed (201) even without a listener on CourseAssigned
        $response->assertCreated();
        $this->assertDatabaseHas('course_assignments', [
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);
    }

    // =========================================================================
    // HR Dashboard batch queries (N+1 fix verification)
    // =========================================================================

    public function test_hr_dashboard_returns_correct_completion_rate_for_multiple_assignments(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
            'kind' => LessonKind::Text,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
            'kind' => LessonKind::Text,
        ]);

        $learner1 = User::factory()->create(['role' => Role::Manager]);
        $learner2 = User::factory()->create(['role' => Role::Manager]);

        $assignment1 = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner1->id,
            'status' => AssignmentStatus::InProgress,
        ]);
        $assignment2 = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner2->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        // learner1: completes 1/2 lessons → 50%
        LessonProgress::factory()->create([
            'assignment_id' => $assignment1->id,
            'lesson_id' => $lesson1->id,
            'completed_at' => now(),
        ]);

        // learner2: completes both → 100%
        LessonProgress::factory()->create([
            'assignment_id' => $assignment2->id,
            'lesson_id' => $lesson1->id,
            'completed_at' => now(),
        ]);
        LessonProgress::factory()->create([
            'assignment_id' => $assignment2->id,
            'lesson_id' => $lesson2->id,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/onboarding/progress');
        $response->assertOk();

        $items = collect($response->json('data'));

        $item1 = $items->firstWhere('assignment_id', $assignment1->id);
        $item2 = $items->firstWhere('assignment_id', $assignment2->id);

        $this->assertNotNull($item1, 'assignment1 must appear in HR dashboard');
        $this->assertNotNull($item2, 'assignment2 must appear in HR dashboard');
        $this->assertSame(50, $item1['progress_pct'], 'learner1: 1/2 lessons = 50%');
        $this->assertSame(100, $item2['progress_pct'], 'learner2: 2/2 lessons = 100%');
    }

    public function test_hr_dashboard_returns_avg_quiz_score(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $quizLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
            'kind' => LessonKind::Quiz,
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id]);

        $learner = User::factory()->create(['role' => Role::Manager]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        // Two passed attempts with scores 80 and 100 → avg = 90
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $learner->id,
            'assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'score_pct' => 80,
            'passed' => true,
            'finished_at' => now()->subMinutes(10),
        ]);
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $learner->id,
            'assignment_id' => $assignment->id,
            'attempt_number' => 2,
            'score_pct' => 100,
            'passed' => true,
            'finished_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/onboarding/progress');
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('assignment_id', $assignment->id);
        $this->assertNotNull($item, 'assignment must appear in HR dashboard');
        $this->assertSame(90, $item['avg_quiz_score'], 'avg of 80+100 = 90');
    }

    public function test_hr_dashboard_avg_quiz_score_null_when_no_passed_attempts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);

        $learner = User::factory()->create(['role' => Role::Manager]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::Pending,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/onboarding/progress');
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('assignment_id', $assignment->id);
        $this->assertNotNull($item, 'assignment must appear in HR dashboard');
        $this->assertNull($item['avg_quiz_score'], 'No passed attempts → null avg_quiz_score');
    }
}
