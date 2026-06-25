<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Events\CourseAssigned;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_bulk_assign_multiple_users(): void
    {
        // Fake only the domain event under test — a bare Event::fake() would
        // also suppress the User model `saved` hook that syncs the spatie role
        // (IAM-1: role is a spatie-backed virtual attribute, no column), leaving
        // the acting admin without an authoritative role → 403.
        Event::fake([CourseAssigned::class]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $managers = User::factory()->count(3)->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => $managers->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseCount('course_assignments', 3);
    }

    public function test_bulk_assign_skips_existing_assignment(): void
    {
        // Fake only the domain event under test — a bare Event::fake() would
        // also suppress the User model `saved` hook that syncs the spatie role
        // (IAM-1: role is a spatie-backed virtual attribute, no column), leaving
        // the acting admin without an authoritative role → 403.
        Event::fake([CourseAssigned::class]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        // Pre-create the assignment
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $manager->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$manager->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1);

        // Still only 1 record (no duplicate)
        $this->assertDatabaseCount('course_assignments', 1);
    }

    public function test_bulk_assign_returns_created_and_skipped_count(): void
    {
        // Fake only the domain event under test — a bare Event::fake() would
        // also suppress the User model `saved` hook that syncs the spatie role
        // (IAM-1: role is a spatie-backed virtual attribute, no column), leaving
        // the acting admin without an authoritative role → 403.
        Event::fake([CourseAssigned::class]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $existing = User::factory()->create(['role' => Role::Manager]);
        $new1 = User::factory()->create(['role' => Role::Manager]);
        $new2 = User::factory()->create(['role' => Role::Manager]);

        // Pre-create one assignment
        CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $existing->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$existing->id, $new1->id, $new2->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_bulk_assign_validates_max_100_user_ids(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);

        Sanctum::actingAs($admin, ['*']);

        // 101 user IDs should fail validation
        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => range(1, 101),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);
    }

    public function test_bulk_assign_validates_course_must_be_published(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => false]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$manager->id],
        ])
            ->assertUnprocessable();
    }

    public function test_bulk_assign_validates_due_date_must_be_future(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$manager->id],
            'due_date' => now()->subDay()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_course_assigned_event_dispatched_for_each_new_assignment(): void
    {
        // Fake only the domain event under test — a bare Event::fake() would
        // also suppress the User model `saved` hook that syncs the spatie role
        // (IAM-1: role is a spatie-backed virtual attribute, no column), leaving
        // the acting admin without an authoritative role → 403.
        Event::fake([CourseAssigned::class]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        $managers = User::factory()->count(2)->create(['role' => Role::Manager]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => $managers->pluck('id')->all(),
        ]);

        Event::assertDispatched(CourseAssigned::class, 2);
    }

    public function test_manager_cannot_bulk_assign(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $other = User::factory()->create(['role' => Role::Manager]);

        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/onboarding/assignments', [
            'course_id' => $course->id,
            'user_ids' => [$other->id],
        ])
            ->assertForbidden();
    }
}
