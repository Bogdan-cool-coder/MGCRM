<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Lesson $quizLesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $this->quizLesson = Lesson::factory()->quiz()->create([
            'module_id' => $module->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Create
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_create_quiz_and_binds_to_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Test Quiz',
            'pass_score_pct' => 75,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Test Quiz')
            ->assertJsonPath('data.pass_score_pct', 75)
            ->assertJsonPath('data.lesson_id', $this->quizLesson->id);

        // Lesson.content.quiz_id must be updated
        $this->quizLesson->refresh();
        $this->assertSame($response->json('data.id'), $this->quizLesson->quiz_id);
    }

    public function test_director_can_create_quiz(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $response = $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Director Quiz',
        ]);

        $response->assertCreated();
    }

    public function test_manager_cannot_create_quiz(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Manager Quiz',
        ])->assertForbidden();
    }

    public function test_cannot_create_quiz_for_non_quiz_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $textLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => 'text',
            'content' => ['markdown' => '# Hello'],
        ]);

        $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $textLesson->id,
            'title' => 'Invalid Quiz',
        ])->assertUnprocessable();
    }

    public function test_cannot_create_second_quiz_for_same_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // First quiz succeeds
        $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'First Quiz',
        ])->assertCreated();

        // Second quiz on same lesson → 422
        $this->postJson('/api/admin/onboarding/quizzes', [
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Second Quiz',
        ])->assertUnprocessable();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Update
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_update_quiz_fields(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        // Manually set lesson.content.quiz_id (factory skips service)
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/onboarding/quizzes/{$quiz->id}", [
            'title' => 'Updated Title',
            'pass_score_pct' => 90,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.pass_score_pct', 90);
    }

    public function test_lesson_id_is_not_updatable(): void
    {
        // lesson_id in payload must be silently ignored (not applied)
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        $course2 = Course::factory()->create();
        $module2 = CourseModule::factory()->create(['course_id' => $course2->id]);
        $otherLesson = Lesson::factory()->quiz()->create(['module_id' => $module2->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/onboarding/quizzes/{$quiz->id}", [
            'lesson_id' => $otherLesson->id,
            'title' => 'Changed',
        ])->assertOk();

        // lesson_id unchanged
        $quiz->refresh();
        $this->assertSame($this->quizLesson->id, $quiz->lesson_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delete
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_quiz_unpublished_lesson(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id], 'is_published' => false]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/quizzes/{$quiz->id}")
            ->assertNoContent();

        $this->assertNull(Quiz::find($quiz->id));

        // lesson.content.quiz_id must be cleared
        $this->quizLesson->refresh();
        $this->assertNull($this->quizLesson->quiz_id);
    }

    public function test_cannot_delete_quiz_published_lesson(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update([
            'content' => ['quiz_id' => $quiz->id],
            'is_published' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/quizzes/{$quiz->id}")
            ->assertUnprocessable();

        $this->assertNotNull(Quiz::find($quiz->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Show
    // ─────────────────────────────────────────────────────────────────────────

    public function test_show_quiz_includes_questions_and_options(): void
    {
        $quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'sort_order' => 1,
        ]);
        QuizOption::factory()->correct()->create([
            'question_id' => $question->id,
            'sort_order' => 1,
        ]);
        QuizOption::factory()->create([
            'question_id' => $question->id,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/admin/onboarding/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonCount(2, 'data.questions.0.options');
    }
}
