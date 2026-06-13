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

class LessonPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private CourseModule $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->course = Course::factory()->create();
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);
    }

    public function test_can_publish_text_lesson(): void
    {
        $lesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'kind' => 'text',
            'content' => ['markdown' => '# Hello'],
            'is_published' => false,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
    }

    public function test_can_publish_video_lesson(): void
    {
        $lesson = Lesson::factory()->video()->create([
            'module_id' => $this->module->id,
            'is_published' => false,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
    }

    public function test_cannot_publish_quiz_lesson_without_quiz_id(): void
    {
        $lesson = Lesson::factory()->quiz(null)->create([
            'module_id' => $this->module->id,
            'is_published' => false,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}/publish")
            ->assertStatus(422);
    }

    public function test_can_publish_quiz_lesson_with_quiz_id(): void
    {
        $lesson = Lesson::factory()->quiz(42)->create([
            'module_id' => $this->module->id,
            'is_published' => false,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
    }

    public function test_can_unpublish_lesson(): void
    {
        $lesson = Lesson::factory()->create([
            'module_id' => $this->module->id,
            'is_published' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.is_published', false);
    }
}
