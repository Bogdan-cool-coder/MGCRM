<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Events\CourseCompleted;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProgressService::class);
    }

    private function makeCourseWithLessons(int $lessonCount = 2, bool $published = true): array
    {
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lessons = collect();
        for ($i = 0; $i < $lessonCount; $i++) {
            $lessons->push(Lesson::factory()->create([
                'module_id' => $module->id,
                'is_published' => $published,
            ]));
        }
        $user = User::factory()->create();
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $user->id,
        ]);

        return [$assignment, $lessons];
    }

    public function test_calc_progress_returns_0_with_no_lesson_progress(): void
    {
        [$assignment] = $this->makeCourseWithLessons(3);

        $this->assertSame(0, $this->service->calcProgress($assignment));
    }

    public function test_calc_progress_returns_correct_pct_partial_completion(): void
    {
        [$assignment, $lessons] = $this->makeCourseWithLessons(4);

        // Complete 1 of 4 lessons → 25%
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lessons[0]->id,
        ]);

        $this->assertSame(25, $this->service->calcProgress($assignment));
    }

    public function test_calc_progress_returns_100_when_all_lessons_completed(): void
    {
        [$assignment, $lessons] = $this->makeCourseWithLessons(2);

        foreach ($lessons as $lesson) {
            LessonProgress::factory()->completed()->create([
                'assignment_id' => $assignment->id,
                'lesson_id' => $lesson->id,
            ]);
        }

        $this->assertSame(100, $this->service->calcProgress($assignment));
    }

    public function test_calc_progress_excludes_unpublished_lessons(): void
    {
        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        $publishedLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => true,
        ]);
        $unpublishedLesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'is_published' => false,
        ]);

        $user = User::factory()->create();
        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $user->id,
        ]);

        // Complete only the published lesson → should be 100%
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $publishedLesson->id,
        ]);

        $this->assertSame(100, $this->service->calcProgress($assignment));
    }

    public function test_is_completed_false_when_not_all_lessons_done(): void
    {
        [$assignment, $lessons] = $this->makeCourseWithLessons(2);

        // Complete only 1 of 2
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lessons[0]->id,
        ]);

        $this->assertFalse($this->service->isCompleted($assignment));
    }

    public function test_is_completed_true_when_all_published_lessons_completed(): void
    {
        [$assignment, $lessons] = $this->makeCourseWithLessons(2);

        foreach ($lessons as $lesson) {
            LessonProgress::factory()->completed()->create([
                'assignment_id' => $assignment->id,
                'lesson_id' => $lesson->id,
            ]);
        }

        $this->assertTrue($this->service->isCompleted($assignment));
    }

    public function test_check_and_complete_transitions_to_completed_and_fires_event(): void
    {
        Event::fake();

        [$assignment, $lessons] = $this->makeCourseWithLessons(2);

        // Complete all lessons
        foreach ($lessons as $lesson) {
            LessonProgress::factory()->completed()->create([
                'assignment_id' => $assignment->id,
                'lesson_id' => $lesson->id,
            ]);
        }

        $this->service->checkAndComplete($assignment);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Completed->value,
        ]);

        Event::assertDispatched(CourseCompleted::class);
    }

    public function test_check_and_complete_no_op_when_not_all_done(): void
    {
        Event::fake();

        [$assignment, $lessons] = $this->makeCourseWithLessons(2);

        // Only complete 1 of 2
        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lessons[0]->id,
        ]);

        $this->service->checkAndComplete($assignment);

        $this->assertDatabaseHas('course_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Pending->value,
        ]);

        Event::assertNotDispatched(CourseCompleted::class);
    }
}
