<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Data\HrDashboardFilters;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Services\OnboardingDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for OnboardingDashboardService pure-PHP methods.
 *
 * Tests use SQLite :memory: with RefreshDatabase — factories create fixtures,
 * but no HTTP layer is involved (pure service calls).
 *
 * Covers: isOverdue() (5), calcAvgQuizScore() (3), topCoursesByAssignments() (3).
 */
class HrDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OnboardingDashboardService::class);
    }

    // -------------------------------------------------------------------------
    // isOverdue() — 5 tests
    // -------------------------------------------------------------------------

    public function test_is_overdue_returns_true_when_status_overdue(): void
    {
        $assignment = CourseAssignment::factory()->create([
            'status' => AssignmentStatus::Overdue->value,
            'due_date' => null,
        ]);

        self::assertTrue($this->service->isOverdue($assignment));
    }

    public function test_is_overdue_returns_true_when_active_and_due_date_past(): void
    {
        $assignment = CourseAssignment::factory()->create([
            'status' => AssignmentStatus::InProgress->value,
            'due_date' => now()->subDay(),
        ]);

        self::assertTrue($this->service->isOverdue($assignment));
    }

    public function test_is_overdue_returns_false_when_completed(): void
    {
        $assignment = CourseAssignment::factory()->create([
            'status' => AssignmentStatus::Completed->value,
            'due_date' => now()->subDay(), // past due, but completed
        ]);

        self::assertFalse($this->service->isOverdue($assignment));
    }

    public function test_is_overdue_returns_false_when_active_and_due_date_future(): void
    {
        $assignment = CourseAssignment::factory()->create([
            'status' => AssignmentStatus::Pending->value,
            'due_date' => now()->addDay(),
        ]);

        self::assertFalse($this->service->isOverdue($assignment));
    }

    public function test_is_overdue_returns_false_when_no_due_date(): void
    {
        $assignment = CourseAssignment::factory()->create([
            'status' => AssignmentStatus::InProgress->value,
            'due_date' => null,
        ]);

        self::assertFalse($this->service->isOverdue($assignment));
    }

    // -------------------------------------------------------------------------
    // calcAvgQuizScore() — 3 tests
    // -------------------------------------------------------------------------

    public function test_avg_quiz_score_returns_null_when_no_passed_attempts(): void
    {
        $assignment = CourseAssignment::factory()->create();

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => false,
            'score_pct' => 40,
            'finished_at' => now(),
        ]);

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => false,
            'score_pct' => 30,
            'finished_at' => now(),
        ]);

        self::assertNull($this->service->calcAvgQuizScore($assignment));
    }

    public function test_avg_quiz_score_ignores_failed_attempts(): void
    {
        $assignment = CourseAssignment::factory()->create();

        // Passed attempt
        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => true,
            'score_pct' => 90,
            'finished_at' => now(),
        ]);

        // Failed attempt — must be ignored
        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => false,
            'score_pct' => 10,
            'finished_at' => now(),
        ]);

        self::assertSame(90, $this->service->calcAvgQuizScore($assignment));
    }

    public function test_avg_quiz_score_averages_multiple_passed(): void
    {
        $assignment = CourseAssignment::factory()->create();

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => true,
            'score_pct' => 80,
            'finished_at' => now(),
        ]);

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => true,
            'score_pct' => 100,
            'finished_at' => now(),
        ]);

        // AVG(80, 100) = 90
        self::assertSame(90, $this->service->calcAvgQuizScore($assignment));
    }

    // -------------------------------------------------------------------------
    // topCoursesByAssignments() — 3 tests
    // -------------------------------------------------------------------------

    public function test_top_courses_no_others_when_exactly_10_courses(): void
    {
        $filters = new HrDashboardFilters(
            userId: null,
            courseId: null,
            status: null,
            includeArchived: false,
            sortBy: 'updated_at',
            sortDir: 'desc',
        );

        // Create 10 assignments, each for a different course
        for ($i = 0; $i < 10; $i++) {
            CourseAssignment::factory()->create(['status' => AssignmentStatus::Pending->value]);
        }

        $payload = $this->service->topCoursesByAssignments($filters, 10);

        self::assertCount(10, $payload['labels']);
        self::assertNotContains('Другие', $payload['labels']);
    }

    public function test_top_courses_others_appears_when_11_plus_courses(): void
    {
        $filters = new HrDashboardFilters(
            userId: null,
            courseId: null,
            status: null,
            includeArchived: false,
            sortBy: 'updated_at',
            sortDir: 'desc',
        );

        // Create 12 assignments, each for a different course
        for ($i = 0; $i < 12; $i++) {
            CourseAssignment::factory()->create(['status' => AssignmentStatus::Pending->value]);
        }

        $payload = $this->service->topCoursesByAssignments($filters, 10);

        // top-10 + Другие = 11
        self::assertCount(11, $payload['labels']);
        self::assertSame('Другие', $payload['labels'][10]);
    }

    public function test_top_courses_others_sum_is_correct(): void
    {
        $filters = new HrDashboardFilters(
            userId: null,
            courseId: null,
            status: null,
            includeArchived: false,
            sortBy: 'updated_at',
            sortDir: 'desc',
        );

        // 12 unique courses, each 1 assignment
        for ($i = 0; $i < 12; $i++) {
            CourseAssignment::factory()->create(['status' => AssignmentStatus::Pending->value]);
        }

        $payload = $this->service->topCoursesByAssignments($filters, 10);

        // 2 courses overflow into «Другие»
        $othersIndex = array_search('Другие', $payload['labels'], strict: true);
        self::assertNotFalse($othersIndex);
        self::assertSame(2, $payload['datasets'][0]['data'][$othersIndex]);
    }
}
