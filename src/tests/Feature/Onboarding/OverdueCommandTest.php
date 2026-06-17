<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeAssignment(string $status, ?string $dueDate): CourseAssignment
    {
        $course = Course::factory()->create(['is_published' => true]);
        $user = User::factory()->create();

        return CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    public function test_overdue_command_marks_pending_assignments_with_past_due_date(): void
    {
        $assignment = $this->makeAssignment('pending', now()->subDay()->toIso8601String());

        $this->artisan('onboarding:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Overdue->value,
        ]);
    }

    public function test_overdue_command_marks_in_progress_assignments_with_past_due_date(): void
    {
        $assignment = $this->makeAssignment('in_progress', now()->subDay()->toIso8601String());

        $this->artisan('onboarding:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Overdue->value,
        ]);
    }

    public function test_overdue_command_does_not_mark_completed_assignments(): void
    {
        $assignment = $this->makeAssignment('completed', now()->subDay()->toIso8601String());

        $this->artisan('onboarding:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => 'completed',
        ]);
    }

    public function test_overdue_command_does_not_mark_assignments_without_due_date(): void
    {
        $assignment = $this->makeAssignment('pending', null);

        $this->artisan('onboarding:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => 'pending',
        ]);
    }

    public function test_overdue_command_returns_count_in_output(): void
    {
        $this->makeAssignment('pending', now()->subDay()->toIso8601String());
        $this->makeAssignment('in_progress', now()->subDay()->toIso8601String());

        $this->artisan('onboarding:mark-overdue')
            ->assertSuccessful()
            ->expectsOutputToContain('2');
    }

    public function test_overdue_command_is_idempotent(): void
    {
        $assignment = $this->makeAssignment('pending', now()->subDay()->toIso8601String());

        $this->artisan('onboarding:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Overdue->value,
        ]);

        // Second run — already overdue, count should be 0
        $this->artisan('onboarding:mark-overdue')
            ->assertSuccessful()
            ->expectsOutputToContain('0');
    }
}
