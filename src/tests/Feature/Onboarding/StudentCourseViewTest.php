<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCourseViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_own_assignments_on_my_courses(): void
    {
        $learner1 = User::factory()->create(['role' => Role::Manager]);
        $learner2 = User::factory()->create(['role' => Role::Manager]);
        $course1 = Course::factory()->create(['is_published' => true]);
        $course2 = Course::factory()->create(['is_published' => true]);

        CourseAssignment::factory()->create([
            'course_id' => $course1->id,
            'user_id' => $learner1->id,
        ]);
        CourseAssignment::factory()->create([
            'course_id' => $course2->id,
            'user_id' => $learner2->id,
        ]);

        Sanctum::actingAs($learner1, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    public function test_my_courses_includes_progress_pct(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk()
            ->assertJsonStructure(['data' => [['assignment_id', 'status', 'progress_pct']]]);

        $this->assertIsInt($response->json('data.0.progress_pct'));
    }

    public function test_my_courses_returns_empty_for_user_with_no_assignments(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson('/api/onboarding/my-courses');
        $response->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_student_can_view_own_assignment_detail(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($learner, ['*']);

        $this->getJson("/api/onboarding/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_id', 'status', 'progress_pct', 'course']]);
    }

    public function test_student_cannot_view_others_assignment_detail(): void
    {
        $learner1 = User::factory()->create(['role' => Role::Manager]);
        $learner2 = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner2->id,
        ]);

        Sanctum::actingAs($learner1, ['*']);

        $this->getJson("/api/onboarding/assignments/{$assignment->id}")
            ->assertForbidden();
    }

    public function test_assignment_detail_includes_lesson_completed_flags(): void
    {
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson1 = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
        ]);
        $lesson2 = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        // Mark lesson1 as completed
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson1->id,
        ]);

        Sanctum::actingAs($learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$assignment->id}");
        $response->assertOk();

        $modules = $response->json('data.course.modules');
        $this->assertNotEmpty($modules);

        $lessons = $modules[0]['lessons'];
        $lessonMap = collect($lessons)->keyBy('id');

        $this->assertTrue($lessonMap[$lesson1->id]['completed']);
        $this->assertFalse($lessonMap[$lesson2->id]['completed']);
    }

    public function test_admin_can_view_any_assignment_detail(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/onboarding/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_id', 'status', 'progress_pct', 'course']]);
    }

    public function test_director_can_view_any_assignment_detail(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $learner = User::factory()->create(['role' => Role::Manager]);
        $course = Course::factory()->create(['is_published' => true]);
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $learner->id,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/onboarding/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['assignment_id', 'status', 'progress_pct', 'course']]);
    }

    public function test_unauthenticated_cannot_access_my_courses(): void
    {
        $this->getJson('/api/onboarding/my-courses')
            ->assertUnauthorized();
    }
}
