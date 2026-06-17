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

class LessonCrudTest extends TestCase
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

    public function test_admin_can_list_lessons_of_module(): void
    {
        Lesson::factory()->count(3)->create(['module_id' => $this->module->id]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/admin/onboarding/modules/{$this->module->id}/lessons")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_create_text_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons", [
            'title' => 'Text Lesson',
            'kind' => 'text',
            'content' => ['markdown' => '# Hello world'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'text')
            ->assertJsonPath('data.content.markdown', '# Hello world');
    }

    public function test_admin_can_create_video_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons", [
            'title' => 'Video Lesson',
            'kind' => 'video',
            'content' => [
                'url' => 'https://www.youtube.com/watch?v=abc123',
                'provider' => 'youtube',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'video')
            ->assertJsonPath('data.content.provider', 'youtube');
    }

    public function test_admin_can_create_pdf_lesson_with_url(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons", [
            'title' => 'PDF Lesson',
            'kind' => 'pdf',
            'content' => ['url' => 'https://example.com/doc.pdf'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'pdf')
            ->assertJsonPath('data.content.url', 'https://example.com/doc.pdf');
    }

    public function test_admin_can_create_quiz_lesson(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/onboarding/modules/{$this->module->id}/lessons", [
            'title' => 'Quiz Lesson',
            'kind' => 'quiz',
            'content' => ['quiz_id' => null],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.kind', 'quiz')
            ->assertJsonPath('data.content.quiz_id', null);
    }

    public function test_admin_can_update_lesson(): void
    {
        $lesson = Lesson::factory()->create(['module_id' => $this->module->id]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}", [
            'title' => 'Updated Title',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_admin_can_delete_lesson(): void
    {
        $lesson = Lesson::factory()->create(['module_id' => $this->module->id]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->deleteJson("/api/admin/onboarding/modules/{$this->module->id}/lessons/{$lesson->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
    }
}
