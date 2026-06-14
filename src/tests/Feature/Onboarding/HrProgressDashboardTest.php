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
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for S3.7 HR-dashboard endpoints.
 *
 * Endpoints:
 *   GET /api/admin/onboarding/progress          — paginated assignment matrix
 *   GET /api/admin/onboarding/progress/summary  — KPI + ECharts payloads
 *
 * Uses SQLite :memory: via RefreshDatabase. Known-count fixtures give
 * deterministic assertions.
 */
class HrProgressDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRESS_URL = '/api/admin/onboarding/progress';

    private const SUMMARY_URL = '/api/admin/onboarding/progress/summary';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function director(): User
    {
        return User::factory()->create(['role' => Role::Director]);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    /**
     * Create a course with $lessonCount published lessons, return the course.
     */
    private function courseWithLessons(int $lessonCount): Course
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        for ($i = 0; $i < $lessonCount; $i++) {
            Lesson::factory()->published()->create([
                'module_id' => $module->id,
                'kind' => 'text',
                'sort_order' => $i + 1,
            ]);
        }

        return $course;
    }

    /**
     * Create a simple assignment (no lesson progress, no quiz attempts).
     */
    private function makeAssignment(
        ?User $user = null,
        ?Course $course = null,
        string $status = 'pending',
        ?\DateTime $dueDate = null,
    ): CourseAssignment {
        return CourseAssignment::factory()->create([
            'user_id' => $user?->id ?? User::factory()->create()->id,
            'course_id' => $course?->id ?? Course::factory()->create()->id,
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    // -------------------------------------------------------------------------
    // Visibility (auth / roles) — 4 tests
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson(self::PROGRESS_URL)->assertUnauthorized();
    }

    public function test_manager_gets_403_on_progress(): void
    {
        Sanctum::actingAs($this->manager(), ['*']);
        $this->getJson(self::PROGRESS_URL)->assertForbidden();
    }

    public function test_director_gets_200_on_progress(): void
    {
        Sanctum::actingAs($this->director(), ['*']);
        $this->getJson(self::PROGRESS_URL)->assertOk();
    }

    public function test_admin_gets_200_on_progress(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $this->getJson(self::PROGRESS_URL)->assertOk();
    }

    // -------------------------------------------------------------------------
    // JSON structure — 2 tests
    // -------------------------------------------------------------------------

    public function test_response_json_structure_matches_contract(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $this->makeAssignment();

        $this->getJson(self::PROGRESS_URL)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'assignment_id',
                        'user_id',
                        'user_name',
                        'course_id',
                        'course_title',
                        'progress_pct',
                        'status',
                        'due_date',
                        'is_overdue',
                        'avg_quiz_score',
                        'assigned_at',
                        'completed_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'filters',
                    'generated_at',
                ],
            ]);
    }

    public function test_summary_structure_matches_contract(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->getJson(self::SUMMARY_URL)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'completed',
                    'in_progress',
                    'pending',
                    'overdue',
                    'status_chart' => ['labels', 'datasets', 'meta'],
                    'top_courses_chart' => ['labels', 'datasets', 'meta'],
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // Director / Admin sees all assignments — 2 tests
    // -------------------------------------------------------------------------

    public function test_director_sees_all_assignments(): void
    {
        $director = $this->director();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $this->makeAssignment($user1);
        $this->makeAssignment($user2);
        $this->makeAssignment($user3);

        Sanctum::actingAs($director, ['*']);

        $this->getJson(self::PROGRESS_URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_admin_sees_all_assignments(): void
    {
        $admin = $this->admin();
        $this->makeAssignment();
        $this->makeAssignment();

        Sanctum::actingAs($admin, ['*']);

        $this->getJson(self::PROGRESS_URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    // -------------------------------------------------------------------------
    // Filters — 4 tests
    // -------------------------------------------------------------------------

    public function test_filter_by_user_id_returns_only_that_user_assignments(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $this->makeAssignment($user1);
        $this->makeAssignment($user2);

        $this->getJson(self::PROGRESS_URL.'?user_id='.$user1->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_id', $user1->id);
    }

    public function test_filter_by_course_id_returns_only_that_course(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $user = User::factory()->create();
        $course1 = Course::factory()->create();
        $course2 = Course::factory()->create();
        $this->makeAssignment($user, $course1);
        $this->makeAssignment($user, $course2);

        $this->getJson(self::PROGRESS_URL.'?course_id='.$course1->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.course_id', $course1->id);
    }

    public function test_filter_by_status_completed_returns_only_completed(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->makeAssignment(status: 'completed');
        $this->makeAssignment(status: 'in_progress');
        $this->makeAssignment(status: 'in_progress');

        $this->getJson(self::PROGRESS_URL.'?status=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_archived_excluded_by_default(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->makeAssignment(status: 'pending');
        $this->makeAssignment(status: 'archived');

        // Without include_archived → 1 row
        $this->getJson(self::PROGRESS_URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // With include_archived=true → 2 rows
        $this->getJson(self::PROGRESS_URL.'?include_archived=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    // -------------------------------------------------------------------------
    // Data correctness — 5 tests
    // -------------------------------------------------------------------------

    public function test_completion_rate_correct(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $course = $this->courseWithLessons(4); // 4 published lessons
        $user = User::factory()->create();
        $assignment = $this->makeAssignment($user, $course, 'in_progress');

        // Complete 2 of 4 lessons → 50%
        $lessonIds = Lesson::whereHas('module', fn ($q) => $q->where('course_id', $course->id))
            ->where('is_published', true)
            ->pluck('id')
            ->take(2);

        foreach ($lessonIds as $lessonId) {
            LessonProgress::factory()->completed()->create([
                'assignment_id' => $assignment->id,
                'lesson_id' => $lessonId,
            ]);
        }

        $response = $this->getJson(self::PROGRESS_URL.'?user_id='.$user->id)->assertOk();
        self::assertSame(50, $response->json('data.0.progress_pct'));
    }

    public function test_completion_rate_zero_for_no_progress(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $course = $this->courseWithLessons(3);
        $user = User::factory()->create();
        $this->makeAssignment($user, $course, 'pending');

        $response = $this->getJson(self::PROGRESS_URL.'?user_id='.$user->id)->assertOk();
        self::assertSame(0, $response->json('data.0.progress_pct'));
    }

    public function test_is_overdue_flag_in_response(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $user = User::factory()->create();
        $this->makeAssignment($user, dueDate: now()->subDay(), status: 'in_progress');

        $response = $this->getJson(self::PROGRESS_URL.'?user_id='.$user->id)->assertOk();
        self::assertTrue($response->json('data.0.is_overdue'));
    }

    public function test_avg_quiz_score_null_when_no_passed_attempts(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $user = User::factory()->create();
        $assignment = $this->makeAssignment($user);

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => false,
            'score_pct' => 40,
            'finished_at' => now(),
        ]);

        $response = $this->getJson(self::PROGRESS_URL.'?user_id='.$user->id)->assertOk();
        self::assertNull($response->json('data.0.avg_quiz_score'));
    }

    public function test_avg_quiz_score_correct_with_passed_attempts(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $user = User::factory()->create();
        $assignment = $this->makeAssignment($user);

        QuizAttempt::factory()->create([
            'assignment_id' => $assignment->id,
            'passed' => true,
            'score_pct' => 80,
            'finished_at' => now(),
        ]);

        $response = $this->getJson(self::PROGRESS_URL.'?user_id='.$user->id)->assertOk();
        self::assertSame(80, $response->json('data.0.avg_quiz_score'));
    }

    // -------------------------------------------------------------------------
    // Pagination — 2 tests
    // -------------------------------------------------------------------------

    public function test_pagination_per_page_15(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        for ($i = 0; $i < 20; $i++) {
            $this->makeAssignment();
        }

        $response = $this->getJson(self::PROGRESS_URL.'?per_page=15')->assertOk();
        self::assertCount(15, $response->json('data'));
        self::assertSame(20, $response->json('meta.total'));
        self::assertSame(2, $response->json('meta.last_page'));
    }

    public function test_sort_by_due_date_asc(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $course = Course::factory()->create();
        $this->makeAssignment(dueDate: now()->addDays(10), course: $course, status: 'pending');
        $this->makeAssignment(dueDate: now()->addDays(3), course: $course, status: 'pending');
        $this->makeAssignment(dueDate: now()->addDays(7), course: $course, status: 'pending');

        $response = $this->getJson(self::PROGRESS_URL.'?sort_by=due_date&sort_dir=asc')->assertOk();
        $dates = array_column($response->json('data'), 'due_date');

        self::assertSame(sort($dates), sort($dates)); // already sorted asc
        // First date should be the earliest (3 days from now)
        self::assertLessThan($dates[1], $dates[0]);
    }

    // -------------------------------------------------------------------------
    // Summary KPI — 2 tests
    // -------------------------------------------------------------------------

    public function test_summary_kpi_counts_correct(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        // 5 pending, 3 in_progress, 4 completed, 2 overdue (via status field)
        for ($i = 0; $i < 5; $i++) {
            $this->makeAssignment(status: 'pending');
        }

        for ($i = 0; $i < 3; $i++) {
            $this->makeAssignment(status: 'in_progress');
        }

        for ($i = 0; $i < 4; $i++) {
            $this->makeAssignment(status: 'completed');
        }

        for ($i = 0; $i < 2; $i++) {
            $this->makeAssignment(status: 'overdue');
        }

        $response = $this->getJson(self::SUMMARY_URL)->assertOk();
        $data = $response->json('data');

        self::assertSame(14, $data['total']);
        self::assertSame(5, $data['pending']);
        self::assertSame(3, $data['in_progress']);
        self::assertSame(4, $data['completed']);
        self::assertSame(2, $data['overdue']);
    }

    public function test_summary_status_chart_labels_and_data_correct(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        for ($i = 0; $i < 5; $i++) {
            $this->makeAssignment(status: 'pending');
        }

        for ($i = 0; $i < 3; $i++) {
            $this->makeAssignment(status: 'in_progress');
        }

        for ($i = 0; $i < 4; $i++) {
            $this->makeAssignment(status: 'completed');
        }

        for ($i = 0; $i < 2; $i++) {
            $this->makeAssignment(status: 'overdue');
        }

        $response = $this->getJson(self::SUMMARY_URL)->assertOk();
        $chart = $response->json('data.status_chart');

        self::assertSame(['Ожидание', 'В процессе', 'Завершено', 'Просрочено'], $chart['labels']);
        self::assertSame([5, 3, 4, 2], $chart['datasets'][0]['data']);
        self::assertSame('pie', $chart['meta']['type']);
    }

    // -------------------------------------------------------------------------
    // Summary top-courses chart — 1 test
    // -------------------------------------------------------------------------

    public function test_summary_top_courses_chart_limited_to_10_plus_others(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        // 12 unique courses × 1 assignment each
        for ($i = 0; $i < 12; $i++) {
            $this->makeAssignment(status: 'pending');
        }

        $response = $this->getJson(self::SUMMARY_URL)->assertOk();
        $chart = $response->json('data.top_courses_chart');

        // top-10 + Другие = 11
        self::assertCount(11, $chart['labels']);
        self::assertSame('Другие', $chart['labels'][10]);
        self::assertSame('bar', $chart['meta']['type']);
        self::assertSame('horizontal', $chart['meta']['orientation']);
    }

    // -------------------------------------------------------------------------
    // Summary 403 for non-admin
    // -------------------------------------------------------------------------

    public function test_manager_gets_403_on_summary(): void
    {
        Sanctum::actingAs($this->manager(), ['*']);
        $this->getJson(self::SUMMARY_URL)->assertForbidden();
    }

    public function test_accountant_gets_403_on_progress(): void
    {
        $accountant = User::factory()->create(['role' => Role::Accountant]);
        Sanctum::actingAs($accountant, ['*']);
        $this->getJson(self::PROGRESS_URL)->assertForbidden();
    }
}
