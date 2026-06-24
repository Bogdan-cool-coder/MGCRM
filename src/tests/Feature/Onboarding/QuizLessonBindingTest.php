<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizLessonBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private CourseModule $module;

    private Lesson $quizLesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $this->course = Course::factory()->create();
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->quizLesson = Lesson::factory()->quiz()->create(['module_id' => $this->module->id]);
    }

    public function test_lesson_content_quiz_id_set_after_quiz_create(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Binding Test Quiz',
        ])->assertCreated();

        $quizId = $response->json('data.id');

        $this->quizLesson->refresh();
        $this->assertSame($quizId, $this->quizLesson->quiz_id);
    }

    public function test_lesson_publish_guard_passes_after_quiz_binding(): void
    {
        // Create quiz via service (attaches quiz_id to lesson content)
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Guard Test Quiz',
        ])->assertCreated();

        // Now attempt to publish the quiz lesson — guard should pass
        $this->postJson(
            "/api/admin/onboarding/modules/{$this->module->id}/lessons/{$this->quizLesson->id}/publish"
        )->assertOk();

        $this->quizLesson->refresh();
        $this->assertTrue($this->quizLesson->is_published);
    }

    public function test_quiz_delete_clears_lesson_content_quiz_id(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update([
            'content' => ['quiz_id' => $quiz->id],
            'is_published' => false,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/quizzes/{$quiz->id}")
            ->assertNoContent();

        $this->quizLesson->refresh();
        $this->assertNull($this->quizLesson->quiz_id);
    }

    public function test_student_resource_hides_is_correct_and_explanation(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        // Publish-gate (#4): lesson must be published for students to access the quiz.
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id], 'is_published' => true]);

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'explanation' => 'Secret explanation',
        ]);
        QuizOption::factory()->correct()->create([
            'question_id' => $question->id,
            'sort_order' => 1,
        ]);
        QuizOption::factory()->create([
            'question_id' => $question->id,
            'sort_order' => 2,
        ]);

        $student = User::factory()->create(['role' => Role::Manager]);
        // S3.4: showForStudent now requires an active assignment
        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $student->id,
        ]);
        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz"
        )->assertOk();

        // explanation must NOT be present in student response
        $this->assertArrayNotHasKey('explanation', $response->json('data.questions.0'));

        // is_correct must NOT be present on options
        $firstOption = $response->json('data.questions.0.options.0');
        $this->assertArrayNotHasKey('is_correct', $firstOption);
    }

    public function test_student_gets_404_for_unpublished_quiz_lesson(): void
    {
        // Publish-gate (#4): unpublished quiz-lesson returns 404 to students.
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);
        // is_published remains false (factory default)

        $student = User::factory()->create(['role' => Role::Manager]);
        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $student->id,
        ]);
        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/onboarding/lessons/{$this->quizLesson->id}/quiz")
            ->assertNotFound();
    }

    public function test_admin_resource_shows_is_correct_and_explanation(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'explanation' => 'Admin sees this',
        ]);
        QuizOption::factory()->correct()->create(['question_id' => $question->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(
            "/api/admin/onboarding/quizzes/{$quiz->id}"
        )->assertOk();

        // explanation present in admin response
        $this->assertSame(
            'Admin sees this',
            $response->json('data.questions.0.explanation')
        );

        // is_correct present on options
        $this->assertArrayHasKey('is_correct', $response->json('data.questions.0.options.0'));
    }
}
