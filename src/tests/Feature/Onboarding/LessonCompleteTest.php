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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Course $course;

    private CourseModule $module;

    private Lesson $textLesson;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => Role::Manager]);
        $this->course = Course::factory()->create(['is_published' => true]);
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
        $this->textLesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'text',
            'is_published' => true,
        ]);
        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::Pending,
        ]);
    }

    public function test_student_can_complete_text_lesson(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $response = $this->postJson(
            "/api/onboarding/lessons/{$this->textLesson->id}/complete",
            ['time_spent_seconds' => 120]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.lesson_id', $this->textLesson->id)
            ->assertJsonPath('data.assignment_id', $this->assignment->id);

        $this->assertDatabaseHas('lesson_progress', [
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->textLesson->id,
            'time_spent_seconds' => 120,
        ]);

        $this->assertNotNull(
            LessonProgress::where('assignment_id', $this->assignment->id)
                ->where('lesson_id', $this->textLesson->id)
                ->value('completed_at')
        );
    }

    public function test_complete_is_idempotent_does_not_change_completed_at(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        // First call
        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete", [
            'time_spent_seconds' => 60,
        ]);

        $firstCompletedAt = LessonProgress::where('assignment_id', $this->assignment->id)
            ->where('lesson_id', $this->textLesson->id)
            ->first()
            ->completed_at
            ->toIso8601String();

        // Brief pause to ensure timestamp would differ if not idempotent
        sleep(1);

        // Second call — completed_at must not change
        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete", [
            'time_spent_seconds' => 90,
        ])->assertOk();

        $secondCompletedAt = LessonProgress::where('assignment_id', $this->assignment->id)
            ->where('lesson_id', $this->textLesson->id)
            ->first()
            ->completed_at
            ->toIso8601String();

        $this->assertSame(
            $firstCompletedAt,
            $secondCompletedAt,
            'completed_at must not change on repeated /complete calls'
        );
    }

    public function test_complete_updates_time_spent_seconds_when_larger(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete", [
            'time_spent_seconds' => 60,
        ])->assertStatus(201);

        // Send larger time value — should update
        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete", [
            'time_spent_seconds' => 300,
        ])->assertOk();

        $this->assertDatabaseHas('lesson_progress', [
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->textLesson->id,
            'time_spent_seconds' => 300,
        ]);
    }

    public function test_complete_returns_403_if_no_assignment(): void
    {
        $otherUser = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($otherUser, ['*']);

        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete")
            ->assertForbidden();
    }

    public function test_complete_returns_403_for_quiz_kind_lesson(): void
    {
        $quizLesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'quiz',
            'is_published' => true,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$quizLesson->id}/complete")
            ->assertForbidden();
    }

    public function test_complete_transitions_assignment_to_in_progress(): void
    {
        // Add a second lesson so completing the first does NOT trigger course completion
        Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'text',
            'is_published' => true,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::Pending->value,
        ]);

        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete")
            ->assertStatus(201);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $this->assignment->id,
            'status' => AssignmentStatus::InProgress->value,
        ]);
    }

    public function test_complete_triggers_course_completed_event_when_last_lesson(): void
    {
        Event::fake();

        Sanctum::actingAs($this->student, ['*']);

        // textLesson is the only published lesson in the course
        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete")
            ->assertStatus(201);

        Event::assertDispatched(CourseCompleted::class, function (CourseCompleted $event) {
            return $event->assignment->id === $this->assignment->id;
        });
    }

    public function test_complete_does_not_trigger_event_when_lessons_remain(): void
    {
        Event::fake();

        // Add a second published lesson (not completed)
        Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'text',
            'is_published' => true,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete")
            ->assertStatus(201);

        Event::assertNotDispatched(CourseCompleted::class);
    }

    public function test_unauthenticated_cannot_complete_lesson(): void
    {
        $this->postJson("/api/onboarding/lessons/{$this->textLesson->id}/complete")
            ->assertUnauthorized();
    }
}
