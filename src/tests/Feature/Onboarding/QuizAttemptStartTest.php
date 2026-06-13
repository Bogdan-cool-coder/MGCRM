<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizAttemptStartTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $quizLesson;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => Role::Manager]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $this->quizLesson = Lesson::factory()->quiz()->create(['module_id' => $module->id]);
        $this->quiz = Quiz::factory()->create(['lesson_id' => $this->quizLesson->id]);
        $this->quizLesson->update(['content' => ['quiz_id' => $this->quiz->id]]);
    }

    public function test_student_can_start_quiz_attempt(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated();

        $response->assertJsonPath('data.quiz_id', $this->quiz->id)
            ->assertJsonPath('data.user_id', $this->student->id)
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.finished_at', null);

        $this->assertDatabaseHas('quiz_attempts', [
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 1,
            'finished_at' => null,
        ]);
    }

    public function test_start_returns_existing_open_attempt(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        // First start
        $first = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated()->json('data.id');

        // Second start — must return same attempt (idempotent)
        $second = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, QuizAttempt::count());
    }

    public function test_attempt_number_increments_after_first_attempt_closed(): void
    {
        // Simulate a previously closed (failed) attempt
        QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => 1,
            'passed' => false,
            'score_pct' => 50,
            'finished_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated();

        $this->assertSame(2, $response->json('data.attempt_number'));
    }

    public function test_attempt_number_starts_at_1_for_new_user(): void
    {
        $newUser = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($newUser, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated();

        $this->assertSame(1, $response->json('data.attempt_number'));
    }

    public function test_start_on_lesson_without_quiz_returns_404(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->quiz()->create([
            'module_id' => $module->id,
            'content' => ['quiz_id' => null],
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$lesson->id}/quiz/start")
            ->assertNotFound();
    }
}
