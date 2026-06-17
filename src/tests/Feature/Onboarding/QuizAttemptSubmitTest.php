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
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizAttemptSubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Course $course;

    private CourseModule $module;

    private Lesson $quizLesson;

    private Quiz $quiz;

    private QuizQuestion $question;

    private QuizOption $correctOption;

    private QuizOption $wrongOption;

    private CourseAssignment $assignment;

    private QuizAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => Role::Manager]);
        $this->course = Course::factory()->create(['is_published' => true]);
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->quizLesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'quiz',
            'is_published' => true,
        ]);
        $this->quiz = Quiz::factory()->create([
            'lesson_id' => $this->quizLesson->id,
            'pass_score_pct' => 80,
        ]);
        // PM-2: points > 0 to avoid division-by-zero in computeScore
        $this->question = QuizQuestion::factory()->create([
            'quiz_id' => $this->quiz->id,
            'points' => 10,
        ]);
        $this->correctOption = QuizOption::factory()->correct()->create([
            'question_id' => $this->question->id,
        ]);
        $this->wrongOption = QuizOption::factory()->create([
            'question_id' => $this->question->id,
            'is_correct' => false,
        ]);

        // Attach quiz to lesson content
        $this->quizLesson->update(['content' => ['quiz_id' => $this->quiz->id]]);

        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::Pending,
        ]);

        $this->attempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'assignment_id' => $this->assignment->id,
            'attempt_number' => 1,
        ]);
    }

    private function correctAnswers(): array
    {
        return [
            'answers' => [
                [
                    'question_id' => $this->question->id,
                    'selected_option_ids' => [$this->correctOption->id],
                ],
            ],
        ];
    }

    private function wrongAnswers(): array
    {
        return [
            'answers' => [
                [
                    'question_id' => $this->question->id,
                    'selected_option_ids' => [$this->wrongOption->id],
                ],
            ],
        ];
    }

    public function test_student_can_submit_attempt_with_correct_answers(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->correctAnswers()
        )->assertOk();

        $response->assertJsonPath('data.score_pct', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.assignment_id', $this->assignment->id);

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $this->attempt->id,
            'score_pct' => 100,
            'passed' => true,
        ]);
    }

    public function test_student_gets_score_pct_and_annotated_answers(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        // Wrong answer → score 0%, passed false (pass_score_pct=80)
        $response = $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->wrongAnswers()
        )->assertOk();

        $response->assertJsonPath('data.score_pct', 0)
            ->assertJsonPath('data.passed', false);

        $answers = $response->json('data.answers');
        $this->assertIsArray($answers);
        $this->assertCount(1, $answers);
        $this->assertSame($this->question->id, $answers[0]['question_id']);
        $this->assertFalse($answers[0]['is_correct']);
    }

    public function test_passed_attempt_creates_lesson_progress_for_quiz_lesson(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->correctAnswers()
        )->assertOk();

        $this->assertDatabaseHas('lesson_progress', [
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->quizLesson->id,
        ]);

        $this->assertNotNull(
            LessonProgress::where('assignment_id', $this->assignment->id)
                ->where('lesson_id', $this->quizLesson->id)
                ->value('completed_at')
        );
    }

    public function test_failed_attempt_does_not_create_lesson_progress(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->wrongAnswers()
        )->assertOk();

        $this->assertDatabaseMissing('lesson_progress', [
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->quizLesson->id,
        ]);
    }

    public function test_submit_returns_409_if_already_submitted(): void
    {
        // Mark as already finished
        $this->attempt->update(['finished_at' => now(), 'score_pct' => 100, 'passed' => true]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->correctAnswers()
        )->assertStatus(409);
    }

    public function test_submit_returns_403_for_foreign_attempt(): void
    {
        $otherUser = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($otherUser, ['*']);

        $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->correctAnswers()
        )->assertForbidden();
    }

    public function test_submit_returns_explanation_in_result(): void
    {
        // Add explanation to question
        $this->question->update(['explanation' => 'The correct answer is A because...']);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->correctAnswers()
        )->assertOk();

        $questionDetails = $response->json('data.question_details');
        $this->assertIsArray($questionDetails);
        $this->assertCount(1, $questionDetails);
        $this->assertSame('The correct answer is A because...', $questionDetails[0]['explanation']);
        $this->assertContains($this->correctOption->id, $questionDetails[0]['correct_option_ids']);
    }

    public function test_submit_assigns_assignment_id_to_attempt(): void
    {
        // Attempt created without assignment_id
        $bareAttempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'assignment_id' => null,
            'attempt_number' => 2,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        // submit will resolve assignment_id from the attempt's quiz course
        $this->postJson(
            "/api/onboarding/quiz-attempts/{$bareAttempt->id}/submit",
            $this->correctAnswers()
        )->assertOk();

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $bareAttempt->id,
            'assignment_id' => $this->assignment->id,
        ]);
    }

    public function test_student_can_retry_after_failed_attempt(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        // Submit and fail
        $this->postJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}/submit",
            $this->wrongAnswers()
        )->assertOk()->assertJsonPath('data.passed', false);

        // Start a new attempt — should get attempt_number = 2
        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->quizLesson->id}/quiz/start"
        )->assertCreated();

        $this->assertSame(2, $response->json('data.attempt_number'));
    }

    public function test_show_attempt_returns_403_for_foreign_attempt(): void
    {
        $otherUser = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($otherUser, ['*']);

        $this->getJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}"
        )->assertForbidden();
    }

    public function test_show_attempt_returns_result_with_details_after_submit(): void
    {
        // Submit first
        $this->attempt->update([
            'score_pct' => 100,
            'passed' => true,
            'answers' => [['question_id' => $this->question->id, 'selected_option_ids' => [$this->correctOption->id], 'is_correct' => true]],
            'finished_at' => now(),
            'assignment_id' => $this->assignment->id,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->getJson(
            "/api/onboarding/quiz-attempts/{$this->attempt->id}"
        )->assertOk();

        $response->assertJsonPath('data.score_pct', 100)
            ->assertJsonPath('data.passed', true);

        $this->assertNotNull($response->json('data.question_details'));
    }
}
