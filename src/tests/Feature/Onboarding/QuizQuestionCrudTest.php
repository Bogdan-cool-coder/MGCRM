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

class QuizQuestionCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $this->quiz = Quiz::factory()->create(['lesson_id' => $lesson->id]);
        $lesson->update(['content' => ['quiz_id' => $this->quiz->id]]);
    }

    public function test_admin_can_create_single_choice_question(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'What is 2+2?',
            'kind' => 'single_choice',
        ])->assertCreated()
            ->assertJsonPath('data.text', 'What is 2+2?')
            ->assertJsonPath('data.kind', 'single_choice');
    }

    public function test_admin_can_create_multiple_choice_question(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Select all prime numbers',
            'kind' => 'multiple_choice',
            'explanation' => 'Primes are 2, 3, 5, 7...',
            'points' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'multiple_choice')
            ->assertJsonPath('data.points', 3)
            ->assertJsonPath('data.explanation', 'Primes are 2, 3, 5, 7...');
    }

    public function test_admin_can_update_question(): void
    {
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $this->quiz->id,
            'text' => 'Old text',
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/onboarding/quiz-questions/{$question->id}", [
            'text' => 'New text',
            'points' => 5,
        ])->assertOk()
            ->assertJsonPath('data.text', 'New text')
            ->assertJsonPath('data.points', 5);
    }

    public function test_admin_can_delete_question_cascade_options(): void
    {
        $question = QuizQuestion::factory()->create(['quiz_id' => $this->quiz->id]);
        $option = QuizOption::factory()->create(['question_id' => $question->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/quiz-questions/{$question->id}")
            ->assertNoContent();

        $this->assertNull(QuizQuestion::find($question->id));
        $this->assertNull(QuizOption::find($option->id));
    }

    public function test_question_sort_order_auto_increments(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $r1 = $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Q1',
            'kind' => 'single_choice',
        ])->assertCreated()->json('data.sort_order');

        $r2 = $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Q2',
            'kind' => 'single_choice',
        ])->assertCreated()->json('data.sort_order');

        $this->assertGreaterThan($r1, $r2);
    }

    public function test_admin_can_reorder_questions(): void
    {
        $q1 = QuizQuestion::factory()->create(['quiz_id' => $this->quiz->id, 'sort_order' => 1]);
        $q2 = QuizQuestion::factory()->create(['quiz_id' => $this->quiz->id, 'sort_order' => 2]);
        $q3 = QuizQuestion::factory()->create(['quiz_id' => $this->quiz->id, 'sort_order' => 3]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            "/api/admin/onboarding/quizzes/{$this->quiz->id}/questions/reorder",
            ['order' => [['id' => $q3->id], ['id' => $q1->id], ['id' => $q2->id]]]
        )->assertOk();

        $data = $response->json('data');
        // After reorder: q3→1, q1→2, q2→3
        $this->assertSame($q3->id, $data[0]['id']);
        $this->assertSame($q1->id, $data[1]['id']);
        $this->assertSame($q2->id, $data[2]['id']);
    }

    public function test_reorder_rejects_foreign_question_id(): void
    {
        // Create a question belonging to a DIFFERENT quiz
        $course2 = Course::factory()->create();
        $module2 = CourseModule::factory()->create(['course_id' => $course2->id]);
        $lesson2 = Lesson::factory()->quiz()->create(['module_id' => $module2->id]);
        $quiz2 = Quiz::factory()->create(['lesson_id' => $lesson2->id]);

        $foreign = QuizQuestion::factory()->create(['quiz_id' => $quiz2->id]);
        $own = QuizQuestion::factory()->create(['quiz_id' => $this->quiz->id]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(
            "/api/admin/onboarding/quizzes/{$this->quiz->id}/questions/reorder",
            ['order' => [['id' => $own->id], ['id' => $foreign->id]]]
        )->assertUnprocessable();
    }

    // ------------------------------------------------------------------
    // Inline options on create (the bug: options were silently dropped)
    // ------------------------------------------------------------------

    public function test_create_question_with_inline_options_persists_option_rows(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Which planet is closest to the Sun?',
            'kind' => 'single_choice',
            'options' => [
                ['text' => 'Mercury', 'is_correct' => true],
                ['text' => 'Venus',   'is_correct' => false],
                ['text' => 'Earth',   'is_correct' => false],
            ],
        ]);

        $response->assertCreated();

        $questionId = $response->json('data.id');
        $this->assertNotNull($questionId);

        // 3 option rows must exist in the DB
        $this->assertSame(3, QuizOption::where('question_id', $questionId)->count());

        // is_correct flag must be persisted correctly
        $correct = QuizOption::where('question_id', $questionId)->where('is_correct', true)->get();
        $this->assertCount(1, $correct);
        $this->assertSame('Mercury', $correct->first()->text);
    }

    public function test_create_question_with_inline_options_returns_options_in_response(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Select all even numbers',
            'kind' => 'multiple_choice',
            'options' => [
                ['text' => '2', 'is_correct' => true],
                ['text' => '3', 'is_correct' => false],
                ['text' => '4', 'is_correct' => true],
            ],
        ]);

        $response->assertCreated();

        $options = $response->json('data.options');
        $this->assertIsArray($options);
        $this->assertCount(3, $options);

        // sort_order must be dense 1..N from array position
        $sortOrders = array_column($options, 'sort_order');
        $this->assertSame([1, 2, 3], $sortOrders);
    }

    public function test_create_question_without_options_key_still_works(): void
    {
        // Regression guard: omitting 'options' must not break existing callers.
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'No options supplied',
            'kind' => 'single_choice',
        ])->assertCreated()
            ->assertJsonPath('data.text', 'No options supplied');

        // Zero options — the question is saved (caller adds options separately)
        $questionId = QuizQuestion::where('text', 'No options supplied')->first()?->id;
        $this->assertNotNull($questionId);
        $this->assertSame(0, QuizOption::where('question_id', $questionId)->count());
    }

    public function test_create_question_options_validation_requires_text(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/quizzes/{$this->quiz->id}/questions", [
            'text' => 'Broken option',
            'kind' => 'single_choice',
            'options' => [
                ['is_correct' => true], // missing 'text'
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.text']);
    }
}
