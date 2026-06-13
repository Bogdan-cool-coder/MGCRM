<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_pdf_for_lesson(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->pdf()->create(['module_id' => $module->id]);
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf');

        $this->postJson("/api/admin/onboarding/lessons/{$lesson->id}/upload", [
            'file' => $file,
        ])->assertOk();
    }

    public function test_upload_updates_lesson_content_path(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->pdf()->create(['module_id' => $module->id]);
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf');

        $response = $this->postJson("/api/admin/onboarding/lessons/{$lesson->id}/upload", [
            'file' => $file,
        ])->assertOk();

        $content = $response->json('data.content');
        $this->assertArrayHasKey('path', $content);
        $this->assertStringContainsString("onboarding/lessons/{$lesson->id}", $content['path']);
    }

    public function test_upload_rejects_non_pdf(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create(['role' => Role::Admin]);
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->pdf()->create(['module_id' => $module->id]);
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->create('image.png', 100, 'image/png');

        $this->postJson("/api/admin/onboarding/lessons/{$lesson->id}/upload", [
            'file' => $file,
        ])->assertStatus(422);
    }
}
