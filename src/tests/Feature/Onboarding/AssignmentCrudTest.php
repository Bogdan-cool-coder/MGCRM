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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentCrudTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_admin_can_list_assignments(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/admin/onboarding/assignments')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_director_can_list_assignments(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->getJson('/api/admin/onboarding/assignments')
            ->assertOk();
    }

    public function test_manager_cannot_list_assignments(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/onboarding/assignments')
            ->assertForbidden();
    }

    // ---- show ----

    public function test_admin_can_get_assignment_detail(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/onboarding/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_id', 'status', 'progress_pct']]);
    }

    // ---- update ----

    public function test_admin_can_update_assignment_due_date(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/admin/onboarding/assignments/{$assignment->id}", [
            'due_date' => now()->addDays(30)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
        ]);
    }

    // ---- archive ----

    public function test_admin_can_archive_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/admin/onboarding/assignments/{$assignment->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', AssignmentStatus::Archived->value);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => 'archived',
        ]);
    }

    // ---- delete ----

    public function test_cannot_delete_assignment_with_lesson_progress(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id, 'is_published' => true]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        LessonProgress::factory()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/assignments/{$assignment->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('course_assignments', ['id' => $assignment->id]);
    }

    public function test_admin_can_delete_assignment_without_progress(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/assignments/{$assignment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('course_assignments', ['id' => $assignment->id]);
    }
}
