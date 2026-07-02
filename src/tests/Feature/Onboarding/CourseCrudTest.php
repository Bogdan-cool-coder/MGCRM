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

class CourseCrudTest extends TestCase
{
    use RefreshDatabase;

    // ---- index ----

    public function test_admin_can_list_courses(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Course::factory()->count(3)->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/onboarding/courses')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_director_can_list_courses(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/onboarding/courses')
            ->assertOk();
    }

    public function test_manager_cannot_list_courses(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/admin/onboarding/courses')
            ->assertForbidden();
    }

    // ---- store ----

    public function test_admin_can_create_course(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/admin/onboarding/courses', [
            'title' => 'Laravel Fundamentals',
            'description' => 'Learn the basics of Laravel',
            'passing_score_pct' => 75,
            'completion_policy' => 'informational',
            'deadline_days' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Laravel Fundamentals')
            ->assertJsonPath('data.passing_score_pct', 75)
            ->assertJsonPath('data.is_published', false);

        $this->assertDatabaseHas('courses', ['title' => 'Laravel Fundamentals']);
    }

    // ---- update ----

    public function test_admin_can_update_course(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['title' => 'Old Title']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/admin/onboarding/courses/{$course->id}", [
            'title' => 'New Title',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title');
    }

    // ---- destroy ----

    public function test_admin_can_delete_course_without_modules(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/admin/onboarding/courses/{$course->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }

    public function test_cannot_delete_course_with_modules(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        CourseModule::factory()->create(['course_id' => $course->id]);
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/admin/onboarding/courses/{$course->id}")
            ->assertStatus(409);
    }

    // ---- index: search filter (q) ----

    public function test_search_filter_matches_title_case_insensitively(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Course::factory()->create(['title' => 'Laravel Основы', 'description' => 'Базовый курс']);
        Course::factory()->create(['title' => 'Vue Продвинутый', 'description' => 'Про компоненты']);
        Course::factory()->create(['title' => 'PHP Углублённый', 'description' => 'laravel internals']);
        Sanctum::actingAs($user, ['*']);

        // 'laravel' (lowercase) must hit "Laravel Основы" (title) AND "PHP Углублённый" (description)
        $response = $this->getJson('/api/admin/onboarding/courses?q=laravel')->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertContains('Laravel Основы', $titles);
        $this->assertContains('PHP Углублённый', $titles);
        $this->assertNotContains('Vue Продвинутый', $titles);
    }

    public function test_search_filter_matches_description(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Course::factory()->create(['title' => 'Курс А', 'description' => 'Onboarding новых сотрудников']);
        Course::factory()->create(['title' => 'Курс Б', 'description' => 'Технический курс']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/onboarding/courses?q=onboarding')->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertContains('Курс А', $titles);
        $this->assertNotContains('Курс Б', $titles);
    }

    // ---- show ----

    public function test_show_course_with_modules_and_lessons(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/admin/onboarding/courses/{$course->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'title', 'modules']]);

        $this->assertNotEmpty($response->json('data.modules'));
    }
}
