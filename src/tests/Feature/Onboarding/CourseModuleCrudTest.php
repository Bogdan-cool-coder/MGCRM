<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseModuleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_modules_of_course(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        CourseModule::factory()->count(2)->create(['course_id' => $course->id]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/admin/onboarding/courses/{$course->id}/modules")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_create_module(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules", [
            'title' => 'Introduction Module',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Introduction Module')
            ->assertJsonPath('data.course_id', $course->id);

        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'title' => 'Introduction Module',
        ]);
    }

    public function test_admin_can_update_module(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'title' => 'Old']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/admin/onboarding/courses/{$course->id}/modules/{$module->id}", [
            'title' => 'Updated Module',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Module');
    }

    public function test_admin_can_delete_module_without_lessons(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/admin/onboarding/courses/{$course->id}/modules/{$module->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('course_modules', ['id' => $module->id]);
    }

    public function test_cannot_delete_module_with_lessons(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/admin/onboarding/courses/{$course->id}/modules/{$module->id}")
            ->assertStatus(409);
    }

    public function test_module_sort_order_auto_increments(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $r1 = $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules", ['title' => 'Module A']);
        $r2 = $this->postJson("/api/admin/onboarding/courses/{$course->id}/modules", ['title' => 'Module B']);

        $r1->assertCreated();
        $r2->assertCreated();

        $sort1 = $r1->json('data.sort_order');
        $sort2 = $r2->json('data.sort_order');

        $this->assertGreaterThan($sort1, $sort2);
    }
}
