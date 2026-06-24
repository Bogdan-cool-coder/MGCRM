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
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for audit MINOR fixes (onboarding.md §6 #7–#13).
 *
 * #7  — deadline_days applied as fallback due_date in bulkAssign
 * #8  — regenerate certificate blocked for non-completed assignments
 * #12 — my-courses progress_pct returned correctly (batch path)
 * #13 — assignment delete blocked when quiz_attempts exist
 */
class AuditMinorFixesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // #7 — deadline_days fallback in bulkAssign
    // =========================================================================

    public function test_bulk_assign_applies_course_deadline_days_when_no_explicit_due_date(): void
    {
        Event::fake();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create([
            'is_published' => true,
            'deadline_days' => 30,
        ]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$learner->id],
            // no due_date — should default to today + 30 days
        ]);

        $response->assertCreated();

        $assignment = CourseAssignment::where('user_id', $learner->id)->first();
        $this->assertNotNull($assignment, 'Assignment must be created.');
        $this->assertNotNull($assignment->due_date, 'due_date must be set from course.deadline_days.');

        // due_date should be approximately today + 30 days (within 1 day tolerance for clock skew)
        $expected = now()->addDays(30);
        $diff = abs($assignment->due_date->diffInDays($expected));
        $this->assertLessThanOrEqual(1, $diff, 'due_date must be ~today+deadline_days.');
    }

    public function test_bulk_assign_explicit_due_date_overrides_course_deadline_days(): void
    {
        Event::fake();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create([
            'is_published' => true,
            'deadline_days' => 30,
        ]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $explicitDate = now()->addDays(7)->toDateString();

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$learner->id],
            'due_date' => $explicitDate,
        ])->assertCreated();

        $assignment = CourseAssignment::where('user_id', $learner->id)->first();
        $this->assertNotNull($assignment->due_date);
        // Should be the explicit 7-day date, not the 30-day fallback
        $diff = abs($assignment->due_date->diffInDays(now()->addDays(7)));
        $this->assertLessThanOrEqual(1, $diff, 'Explicit due_date must take precedence over deadline_days.');
    }

    public function test_bulk_assign_no_due_date_when_course_has_no_deadline_days(): void
    {
        Event::fake();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create([
            'is_published' => true,
            'deadline_days' => null,
        ]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$learner->id],
        ])->assertCreated();

        $assignment = CourseAssignment::where('user_id', $learner->id)->first();
        $this->assertNull($assignment->due_date, 'due_date should remain null when course has no deadline_days.');
    }

    // =========================================================================
    // #8 — Certificate regeneration completion guard
    // =========================================================================

    public function test_regenerate_certificate_blocked_for_incomplete_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        // Assignment in_progress — NOT completed
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/admin/onboarding/certificates/{$assignment->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Certificate can only be regenerated for completed assignments.']);
    }

    public function test_regenerate_certificate_blocked_for_pending_assignment(): void
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

        $this->postJson("/api/admin/onboarding/certificates/{$assignment->id}/regenerate")
            ->assertUnprocessable();
    }

    public function test_regenerate_certificate_allowed_for_completed_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        // Completed assignment — regeneration is allowed (202 expected)
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::Completed,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);

        // We only assert the 202 and not the actual job dispatch (integration tested separately)
        $this->postJson("/api/admin/onboarding/certificates/{$assignment->id}/regenerate")
            ->assertAccepted();
    }

    // =========================================================================
    // #12 — my-courses progress_pct (batch path)
    // =========================================================================

    public function test_my_courses_returns_correct_progress_pct(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        // Complete 1 of 2 lessons → 50%
        LessonProgress::factory()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson1->id,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk();

        $progressPct = $response->json('data.0.progress_pct');
        $this->assertSame(50, $progressPct, 'progress_pct must reflect 1/2 completed lessons = 50%.');
    }

    public function test_my_courses_progress_pct_is_zero_with_no_progress(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Text,
            'is_published' => true,
        ]);

        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk();
        $this->assertSame(0, $response->json('data.0.progress_pct'));
    }

    // =========================================================================
    // #13 — Delete guard extended to quiz_attempts
    // =========================================================================

    public function test_assignment_cannot_be_deleted_when_quiz_attempts_exist(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $quizLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => LessonKind::Quiz,
            'is_published' => true,
        ]);
        $quiz = Quiz::factory()->create(['lesson_id' => $quizLesson->id]);
        $learner = User::factory()->create(['role' => Role::Manager]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        // Create a quiz attempt — no lesson_progress (guard was already lesson-only)
        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'quiz_id' => $quiz->id,
            'user_id' => $learner->id,
            'attempt_number' => 1,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/assignments/{$assignment->id}")
            ->assertStatus(409);

        // Assignment still exists
        $this->assertNotNull(CourseAssignment::find($assignment->id));
    }

    public function test_assignment_can_be_deleted_when_no_progress_or_attempts(): void
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

        $this->deleteJson("/api/admin/onboarding/assignments/{$assignment->id}")
            ->assertNoContent();

        $this->assertNull(CourseAssignment::find($assignment->id));
    }
}
