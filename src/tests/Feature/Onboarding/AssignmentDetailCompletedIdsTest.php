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

/**
 * BUG-LESSON-TREE: assignment detail must return completed_lesson_ids as a flat
 * array so the frontend can restore checkbox state on reload without traversing
 * the nested module/lesson tree.
 */
class AssignmentDetailCompletedIdsTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Course $course;

    private CourseModule $module;

    private Lesson $lesson1;

    private Lesson $lesson2;

    private Lesson $lesson3;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learner = User::factory()->create(['role' => Role::Manager]);
        $this->course = Course::factory()->create(['is_published' => true]);
        $this->module = CourseModule::factory()->create(['course_id' => $this->course->id]);

        $this->lesson1 = Lesson::factory()->create(['module_id' => $this->module->id, 'is_published' => true]);
        $this->lesson2 = Lesson::factory()->create(['module_id' => $this->module->id, 'is_published' => true]);
        $this->lesson3 = Lesson::factory()->create(['module_id' => $this->module->id, 'is_published' => true]);

        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->learner->id,
        ]);
    }

    public function test_completed_lesson_ids_present_in_response(): void
    {
        Sanctum::actingAs($this->learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['completed_lesson_ids']]);

        $this->assertIsArray($response->json('data.completed_lesson_ids'));
    }

    public function test_completed_lesson_ids_empty_when_no_progress(): void
    {
        Sanctum::actingAs($this->learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk();

        $ids = $response->json('data.completed_lesson_ids');
        $this->assertEmpty($ids);
    }

    public function test_completed_lesson_ids_contains_completed_lessons(): void
    {
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->lesson1->id,
        ]);
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->lesson3->id,
        ]);

        Sanctum::actingAs($this->learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk();

        $ids = $response->json('data.completed_lesson_ids');
        $this->assertContains($this->lesson1->id, $ids);
        $this->assertContains($this->lesson3->id, $ids);
        $this->assertNotContains($this->lesson2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_incomplete_lesson_progress_not_in_completed_ids(): void
    {
        // Record exists but completed_at is null (in_progress).
        LessonProgress::factory()->create([
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->lesson1->id,
            'completed_at' => null,
        ]);

        Sanctum::actingAs($this->learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk();

        $ids = $response->json('data.completed_lesson_ids');
        $this->assertNotContains($this->lesson1->id, $ids);
        $this->assertEmpty($ids);
    }

    public function test_completed_flags_in_tree_match_completed_lesson_ids(): void
    {
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->lesson2->id,
        ]);

        Sanctum::actingAs($this->learner, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk();

        $flatIds = $response->json('data.completed_lesson_ids');
        $treeLessons = $response->json('data.course.modules.0.lessons');

        $treeCompletedIds = collect($treeLessons)
            ->where('completed', true)
            ->pluck('id')
            ->all();

        sort($flatIds);
        sort($treeCompletedIds);

        // flat array and tree completed flags must be consistent
        $this->assertSame($treeCompletedIds, $flatIds);
    }

    public function test_admin_sees_completed_lesson_ids_for_any_assignment(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        LessonProgress::factory()->completed()->create([
            'assignment_id' => $this->assignment->id,
            'lesson_id' => $this->lesson1->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/onboarding/assignments/{$this->assignment->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['completed_lesson_ids']]);

        $ids = $response->json('data.completed_lesson_ids');
        $this->assertContains($this->lesson1->id, $ids);
    }
}
