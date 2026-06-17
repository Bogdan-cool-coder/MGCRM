<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Events\CourseCompleted;
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

class CourseCompletionTest extends TestCase
{
    use RefreshDatabase;

    private ProgressService $service;

    private User $student;

    private Course $course;

    private CourseModule $module;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProgressService::class);
        $this->student = User::factory()->create(['role' => Role::Manager]);
        $this->course = Course::factory()->create(['is_published' => true]);
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::InProgress,
        ]);
    }

    public function test_course_completes_when_all_text_lessons_done(): void
    {
        Event::fake();

        $lesson1 = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'text', 'is_published' => true]);
        $lesson2 = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'video', 'is_published' => true]);

        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $lesson1->id]);
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $lesson2->id]);

        $this->service->checkAndComplete($this->assignment);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::Completed->value,
        ]);

        Event::assertDispatched(CourseCompleted::class, function (CourseCompleted $event) {
            return $event->assignment->id === $this->assignment->id;
        });
    }

    public function test_course_does_not_complete_if_quiz_lesson_not_passed(): void
    {
        Event::fake();

        $textLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'text', 'is_published' => true]);
        $quizLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'quiz', 'is_published' => true]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id, 'pass_score_pct' => 80]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        // Complete text lesson AND quiz lesson progress (no passed quiz attempt yet)
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $textLesson->id]);
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $quizLesson->id]);

        // Quiz attempt exists but NOT passed
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'assignment_id' => $this->assignment->id,
            'passed' => false,
            'score_pct' => 50,
            'finished_at' => now(),
        ]);

        $this->service->checkAndComplete($this->assignment);

        // Should NOT be completed
        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::InProgress->value,
        ]);

        Event::assertNotDispatched(CourseCompleted::class);
    }

    public function test_course_completes_when_quiz_lesson_passed(): void
    {
        Event::fake();

        $textLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'text', 'is_published' => true]);
        $quizLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'quiz', 'is_published' => true]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id, 'pass_score_pct' => 80]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        // Complete text + quiz lesson progress
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $textLesson->id]);
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $quizLesson->id]);

        // Passed quiz attempt
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'assignment_id' => $this->assignment->id,
            'passed' => true,
            'score_pct' => 100,
            'finished_at' => now(),
        ]);

        $this->service->checkAndComplete($this->assignment);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::Completed->value,
        ]);

        Event::assertDispatched(CourseCompleted::class);
    }

    public function test_course_completed_event_not_fired_twice(): void
    {
        Event::fake();

        $lesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'text', 'is_published' => true]);
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $lesson->id]);

        // First call — should complete and fire event
        $this->service->checkAndComplete($this->assignment);

        // Reload to get updated status
        $this->assignment->refresh();

        // Second call — already completed, should be no-op
        $this->service->checkAndComplete($this->assignment);

        // Event dispatched exactly once
        Event::assertDispatchedTimes(CourseCompleted::class, 1);
    }

    public function test_is_completed_false_with_zero_lessons(): void
    {
        // No lessons in this course — empty course cannot be completed
        $this->assertFalse($this->service->isCompleted($this->assignment));
    }

    public function test_is_completed_requires_quiz_attempt_passed_for_quiz_lesson(): void
    {
        $quizLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'quiz', 'is_published' => true]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id, 'pass_score_pct' => 80]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        // Quiz lesson has progress record but no passed attempt
        LessonProgress::factory()->completed()->create(['assignment_id' => $this->assignment->id, 'lesson_id' => $quizLesson->id]);

        $this->assertFalse($this->service->isCompleted($this->assignment));
    }

    public function test_course_completion_via_http_complete_and_submit(): void
    {
        Event::fake();

        Sanctum::actingAs($this->student, ['*']);

        // One text lesson + one quiz lesson
        $textLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'text', 'is_published' => true]);
        $quizLesson = Lesson::factory()->create(['module_id' => $this->module->id, 'kind' => 'quiz', 'is_published' => true]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id, 'pass_score_pct' => 80]);
        $quizLesson->update(['content' => ['quiz_id' => $quiz->id]]);

        // PM-2: question with points > 0
        $question = QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'points' => 10]);
        $correctOption = QuizOption::factory()->correct()->create(['question_id' => $question->id]);
        QuizOption::factory()->create(['question_id' => $question->id, 'is_correct' => false]);

        // Complete text lesson
        $this->postJson("/api/onboarding/lessons/{$textLesson->id}/complete")
            ->assertStatus(201);

        // Start quiz attempt
        $attemptId = $this->postJson("/api/onboarding/lessons/{$quizLesson->id}/quiz/start")
            ->assertCreated()
            ->json('data.id');

        // Submit quiz with correct answers
        $this->postJson("/api/onboarding/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ])->assertOk()->assertJsonPath('data.passed', true);

        // Course should be completed
        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::Completed->value,
        ]);

        Event::assertDispatched(CourseCompleted::class);
    }
}
