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
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression locks for audit blockers (onboarding.md §6 #1–#3 + #4).
 *
 * onboarding#0 — Blank lesson player: AssignmentDetailResource must expose lesson `content`.
 * onboarding#1 — Quiz question edit 404: FE nested routes (/quizzes/{q}/questions/{id}) must exist.
 * onboarding#2 — AI drafts served+scored: is_draft=true questions must be excluded from
 *                student-facing quiz and from computeScore.
 * onboarding#4 — Publish gate: unpublished lessons must be filtered from student payload.
 */
class AuditBlockerFixesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // onboarding#0 — Lesson content exposed to students
    // =========================================================================

    public function test_assignment_detail_includes_lesson_content_for_text_lesson(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'content' => ['markdown' => '# Welcome to MACRO CRM\n\nThis is test content.'],
            'duration_minutes' => 5,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessons = $response->json('data.course.modules.0.lessons');
        $this->assertNotEmpty($lessons, 'Lesson list must not be empty.');

        $lessonPayload = $lessons[0];
        $this->assertArrayHasKey('content', $lessonPayload, 'Lesson payload must include content field.');
        $this->assertArrayHasKey('duration_minutes', $lessonPayload, 'Lesson payload must include duration_minutes.');
        $this->assertNotEmpty($lessonPayload['content'], 'Lesson content must not be empty.');
        $this->assertSame(5, $lessonPayload['duration_minutes']);

        // Verify the markdown body is present
        $this->assertArrayHasKey('markdown', $lessonPayload['content']);
    }

    public function test_assignment_detail_includes_lesson_content_for_video_lesson(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Video,
            'is_published' => true,
            'content' => ['url' => 'https://www.youtube.com/watch?v=abc123'],
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessonPayload = $response->json('data.course.modules.0.lessons.0');
        $this->assertNotNull($lessonPayload);
        $this->assertArrayHasKey('content', $lessonPayload);
        $this->assertArrayHasKey('url', $lessonPayload['content']);
    }

    // =========================================================================
    // onboarding#4 — Publish gate: draft lessons must be filtered from student
    // =========================================================================

    public function test_assignment_detail_filters_unpublished_lessons(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        $publishedLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
            'content' => ['markdown' => '# Published'],
        ]);

        $draftLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => false,
            'content' => ['markdown' => '# Draft — should not appear'],
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $lessons = $response->json('data.course.modules.0.lessons');
        $lessonIds = collect($lessons)->pluck('id')->all();

        $this->assertContains($publishedLesson->id, $lessonIds, 'Published lesson must appear.');
        $this->assertNotContains($draftLesson->id, $lessonIds, 'Draft lesson must NOT appear in student payload.');
    }

    // =========================================================================
    // onboarding#1 — Nested quiz question routes (FE patch/delete path)
    // =========================================================================

    public function test_nested_patch_question_route_works(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Old text',
        ]);

        Sanctum::actingAs($admin, ['*']);

        // FE path: /quizzes/{quizId}/questions/{questionId}
        $this->patchJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}/questions/{$question->id}",
            ['text' => 'New text via nested route']
        )->assertOk()
            ->assertJsonPath('data.text', 'New text via nested route');
    }

    public function test_nested_delete_question_route_works(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        Sanctum::actingAs($admin, ['*']);

        // FE path: /quizzes/{quizId}/questions/{questionId}
        $this->deleteJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}/questions/{$question->id}"
        )->assertNoContent();

        $this->assertNull(QuizQuestion::find($question->id));
    }

    public function test_nested_create_option_route_works(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        Sanctum::actingAs($admin, ['*']);

        // FE path: /quizzes/{quizId}/questions/{questionId}/options
        $this->postJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}/questions/{$question->id}/options",
            ['text' => 'Option A', 'is_correct' => true]
        )->assertCreated()
            ->assertJsonPath('data.text', 'Option A')
            ->assertJsonPath('data.is_correct', true);
    }

    public function test_nested_patch_option_route_works(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);
        $option = QuizOption::factory()->create(['question_id' => $question->id, 'text' => 'Old']);

        Sanctum::actingAs($admin, ['*']);

        // FE path: /quizzes/{quizId}/questions/{questionId}/options/{optionId}
        $this->patchJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}/questions/{$question->id}/options/{$option->id}",
            ['text' => 'New option text']
        )->assertOk()
            ->assertJsonPath('data.text', 'New option text');
    }

    public function test_nested_delete_option_route_works(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);
        $option = QuizOption::factory()->create(['question_id' => $question->id]);

        Sanctum::actingAs($admin, ['*']);

        // FE path: /quizzes/{quizId}/questions/{questionId}/options/{optionId}
        $this->deleteJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}/questions/{$question->id}/options/{$option->id}"
        )->assertNoContent();

        $this->assertNull(QuizOption::find($option->id));
    }

    // =========================================================================
    // onboarding#2 — AI drafts excluded from student quiz and scoring
    // =========================================================================

    public function test_draft_questions_excluded_from_student_quiz(): void
    {
        $student = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $quizLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Quiz,
            'is_published' => true,
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        // One approved question, one AI draft
        QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Approved question',
            'is_draft' => false,
        ]);
        QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'AI draft — not approved',
            'is_draft' => true,
        ]);

        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Pending,
        ]);

        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson("/api/onboarding/lessons/{$quizLesson->id}/quiz");
        $response->assertOk();

        $questions = $response->json('data.questions');
        $this->assertCount(1, $questions, 'Student must only see approved (non-draft) questions.');
        $this->assertSame('Approved question', $questions[0]['text']);
    }

    public function test_draft_questions_not_scored_on_submit(): void
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

        // One approved question (the student will answer correctly)
        $approvedQ = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Approved',
            'points' => 10,
            'is_draft' => false,
        ]);
        $correctOpt = QuizOption::factory()->create([
            'question_id' => $approvedQ->id,
            'is_correct' => true,
        ]);

        // One AI draft question with a wrong option — must NOT enter the score
        $draftQ = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'AI draft',
            'points' => 10,
            'is_draft' => true,
        ]);
        QuizOption::factory()->create([
            'question_id' => $draftQ->id,
            'is_correct' => false,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => AssignmentStatus::Pending,
        ]);

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
            'attempt_number' => 1,
        ]);

        Sanctum::actingAs($student, ['*']);

        // Answer only the approved question correctly (draft is ignored)
        $response = $this->postJson(
            "/api/onboarding/quiz-attempts/{$attempt->id}/submit",
            [
                'answers' => [
                    ['question_id' => $approvedQ->id, 'selected_option_ids' => [$correctOpt->id]],
                ],
            ]
        )->assertOk();

        // score_pct = 100% because the only scored question was answered correctly;
        // the draft question does NOT drag the score down.
        $this->assertSame(100, $response->json('data.score_pct'));
        $this->assertTrue($response->json('data.passed'));
    }

    public function test_admin_sees_draft_questions_in_quiz_resource(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $lesson->update(['content' => ['quiz_id' => $quiz->id]]);

        QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'Approved',
            'is_draft' => false,
        ]);
        QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'AI draft',
            'is_draft' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/quizzes/{$quiz->id}");
        $response->assertOk();

        $questions = $response->json('data.questions');
        $this->assertCount(2, $questions, 'Admin must see all questions including drafts.');

        $draftQuestion = collect($questions)->firstWhere('is_draft', true);
        $this->assertNotNull($draftQuestion, 'Admin resource must expose is_draft flag.');
    }

    // =========================================================================
    // onboarding#5 — ai_generation_status surfaced in QuizAdminResource
    // =========================================================================

    public function test_quiz_admin_resource_exposes_ai_generation_status_idle_when_no_generation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create([
            'module_id' => $module->id,
            'content' => [], // no ai_generation_status
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/quizzes/{$quiz->id}");
        $response->assertOk()
            ->assertJsonStructure(['data' => ['ai_generation_status']]);

        $this->assertSame('idle', $response->json('data.ai_generation_status'));
    }

    public function test_quiz_admin_resource_normalises_done_to_completed(): void
    {
        // BE writes 'done'; FE polls for 'completed'. Resource must normalise.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create([
            'module_id' => $module->id,
            'content' => ['ai_generation_status' => 'done'],
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/quizzes/{$quiz->id}");
        $response->assertOk();

        $this->assertSame(
            'completed',
            $response->json('data.ai_generation_status'),
            "Resource must map 'done' → 'completed' so FE useAiQuizGeneration poll resolves."
        );
    }

    public function test_quiz_admin_resource_exposes_pending_status(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create([
            'module_id' => $module->id,
            'content' => ['ai_generation_status' => 'pending'],
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/quizzes/{$quiz->id}");
        $response->assertOk();

        $this->assertSame('pending', $response->json('data.ai_generation_status'));
    }

    // =========================================================================
    // NEW-8 — MyCourses resource emits 'id' (not 'assignment_id')
    // =========================================================================

    public function test_my_courses_resource_emits_id_field(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk();

        $first = $response->json('data.0');
        $this->assertArrayHasKey('id', $first, 'MyCourses payload must include `id` for router.push navigation.');
        $this->assertArrayNotHasKey('assignment_id', $first, '`assignment_id` was renamed to `id` — old key must not appear.');
        $this->assertSame($assignment->id, $first['id']);
    }

    public function test_hr_can_approve_draft_question_via_patch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);

        $draftQ = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'text' => 'AI draft question',
            'is_draft' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);

        // HR approves the draft by setting is_draft=false
        $this->patchJson(
            "/api/admin/onboarding/quiz-questions/{$draftQ->id}",
            ['is_draft' => false]
        )->assertOk()
            ->assertJsonPath('data.is_draft', false);

        $this->assertDatabaseHas('quiz_questions', [
            'id' => $draftQ->id,
            'is_draft' => false,
        ]);
    }
}
