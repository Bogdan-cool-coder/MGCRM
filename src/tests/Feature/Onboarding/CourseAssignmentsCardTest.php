<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-API-1: GET /api/admin/onboarding/courses/{course}/assignments
 * Missing route — CourseAssignmentsCard always showed 0.
 */
class CourseAssignmentsCardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $director;

    private User $manager;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->director = User::factory()->create(['role' => Role::Director]);
        $this->manager = User::factory()->create(['role' => Role::Manager]);
        $this->course = Course::factory()->create(['is_published' => true]);
    }

    public function test_admin_can_list_course_assignments(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);

        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $learner->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments");

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(1, 'data');
    }

    public function test_director_can_list_course_assignments(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($this->director, ['*']);

        $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments")
            ->assertOk();
    }

    public function test_manager_cannot_list_course_assignments(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments")
            ->assertForbidden();
    }

    public function test_response_includes_user_name_flat(): void
    {
        $learner = User::factory()->create([
            'role' => Role::Manager,
            'full_name' => 'Ivan Petrov',
        ]);

        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments");

        $response->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('user_name', $item);
        $this->assertArrayHasKey('user_id', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('progress_pct', $item);
        // user_name must be flat string, not a nested object
        $this->assertIsString($item['user_name']);
    }

    public function test_response_excludes_other_course_assignments(): void
    {
        $otherCourse = Course::factory()->create(['is_published' => true]);
        $learner1 = User::factory()->create(['role' => Role::Manager]);
        $learner2 = User::factory()->create(['role' => Role::Manager]);

        CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $learner1->id,
        ]);
        CourseAssignment::factory()->create([
            'course_id' => $otherCourse->id,
            'user_id' => $learner2->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_returns_empty_when_course_has_no_assignments(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_returns_404_for_unknown_course(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/onboarding/courses/99999/assignments')
            ->assertNotFound();
    }

    public function test_unauthenticated_cannot_list_course_assignments(): void
    {
        $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments")
            ->assertUnauthorized();
    }

    public function test_response_is_paginated(): void
    {
        $learners = User::factory()->count(5)->create(['role' => Role::Manager]);

        foreach ($learners as $learner) {
            CourseAssignment::factory()->create([
                'course_id' => $this->course->id,
                'user_id' => $learner->id,
            ]);
        }

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$this->course->id}/assignments?per_page=3");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 5);
    }
}
