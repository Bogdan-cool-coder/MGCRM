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

class CoursePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_publish_course_without_published_lessons(): void
    {
        // The authenticated user's stored locale drives the message language
        // (SetLocale); pin it to en so the assertion is deterministic.
        $user = User::factory()->create(['role' => Role::Admin, 'locale' => 'en']);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        // Lesson exists but is NOT published
        Lesson::factory()->create(['module_id' => $module->id, 'is_published' => false]);
        Sanctum::actingAs($user, ['*']);

        // The 422 must carry the precise, localized "lessons exist but none
        // published" reason so the FE can tell the admin exactly what to fix.
        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', __('onboarding.publish.no_published_lesson', [], 'en'))
            ->assertJsonPath('errors.course.0', __('onboarding.publish.no_published_lesson', [], 'en'));
    }

    public function test_publish_block_reason_is_localized_in_russian(): void
    {
        $user = User::factory()->create(['role' => Role::Admin, 'locale' => 'ru']);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id, 'is_published' => false]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', __('onboarding.publish.no_published_lesson', [], 'ru'));
    }

    public function test_cannot_publish_course_without_any_modules(): void
    {
        $user = User::factory()->create(['role' => Role::Admin, 'locale' => 'en']);
        $course = Course::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', __('onboarding.publish.no_modules', [], 'en'));
    }

    public function test_cannot_publish_course_with_modules_but_no_lessons(): void
    {
        $user = User::factory()->create(['role' => Role::Admin, 'locale' => 'en']);
        $course = Course::factory()->create();
        CourseModule::factory()->create(['course_id' => $course->id]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', __('onboarding.publish.no_lessons', [], 'en'));
    }

    public function test_can_publish_course_with_published_lesson(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => false]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
    }

    public function test_can_unpublish_published_course(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create(['is_published' => true]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.is_published', false);
    }

    public function test_publish_requires_admin_or_director(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/admin/onboarding/courses/{$course->id}/publish")
            ->assertForbidden();
    }
}
