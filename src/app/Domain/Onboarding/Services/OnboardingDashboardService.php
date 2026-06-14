<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Data\HrDashboardFilters;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OnboardingDashboardService — HR-dashboard aggregator (S3.7).
 *
 * Pattern: 1-in-1 with SalesDashboardService (S1.7).
 *
 * All SQL aggregations use GROUP BY / CASE WHEN. N+1 mitigation:
 * - eager-load user + course with baseQuery
 * - lessonIdsCache[] per course_id (one DB hit per unique course, not per row)
 * - calcAvgQuizScore: one AVG query per assignment_id (uses ix_quiz_attempts_assignment_passed)
 *
 * Money: not applicable here. All counts are integers. score_pct is 0-100 integer.
 */
class OnboardingDashboardService
{
    /**
     * PHP-side cache: course_id → Collection<int> of published lesson IDs.
     * Avoids repeated identical queries when multiple assignments share a course.
     *
     * @var array<int, Collection<int, int>>
     */
    private array $lessonIdsCache = [];

    public function __construct(
        private readonly ProgressService $progressService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Paginated list of assignments with per-row aggregates.
     *
     * @return LengthAwarePaginator<CourseAssignment>
     */
    public function getHrDashboard(HrDashboardFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->baseQuery($filters)
            ->paginate($perPage);

        // Transform: enrich each row with computed fields (completion_rate / overdue / avg_score).
        // lessonIdsCache ensures each unique course is fetched only once.
        $paginator->getCollection()->transform(
            fn (CourseAssignment $assignment): array => $this->enrichRow($assignment)
        );

        return $paginator;
    }

    /**
     * Summary: 4 KPI counters + 2 ECharts chart payloads.
     *
     * overdue_count uses SQL CASE WHEN (single query, cron-delay ≤24h accepted for KPI).
     *
     * @return array<string, mixed>
     */
    public function getSummary(HrDashboardFilters $filters): array
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        // Build the NOW() expression portably.
        $nowExpr = $isPg ? 'NOW()' : "datetime('now')";

        // Use aggregateQuery (no ORDER BY) to avoid PG SQLSTATE[42803]:
        // "column must appear in GROUP BY or be used in an aggregate function"
        // baseQuery() adds ->orderBy() which conflicts with pure aggregate SELECTs on PG.
        $row = $this->aggregateQuery($filters)
            ->selectRaw(
                'COUNT(*) as total_assignments,'.
                "COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,".
                "COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,".
                "COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,".
                "COUNT(CASE WHEN status = 'overdue' OR (status IN ('pending','in_progress') AND due_date IS NOT NULL AND due_date < {$nowExpr}) THEN 1 END) as overdue_count"
            )
            ->first();

        $kpi = [
            'total_assignments' => (int) ($row?->total_assignments ?? 0),
            'pending_count' => (int) ($row?->pending_count ?? 0),
            'in_progress_count' => (int) ($row?->in_progress_count ?? 0),
            'completed_count' => (int) ($row?->completed_count ?? 0),
            'overdue_count' => (int) ($row?->overdue_count ?? 0),
        ];

        return [
            'kpi' => $kpi,
            'status_chart' => $this->buildStatusPiePayload($kpi),
            'top_courses_chart' => $this->topCoursesByAssignments($filters, 10),
        ];
    }

    // -------------------------------------------------------------------------
    // Private: base query + filters
    // -------------------------------------------------------------------------

    /**
     * Aggregate-safe query: same filters as baseQuery but WITHOUT ORDER BY.
     *
     * PG SQLSTATE[42803]: when a SELECT has COUNT(*)/GROUP BY, any ORDER BY
     * column must either be in GROUP BY or be an aggregate itself. The eager-load
     * ORDER BY added by baseQuery() violates this. Use aggregateQuery() for all
     * getSummary / topCoursesByAssignments callers.
     *
     * @return Builder<CourseAssignment>
     */
    private function aggregateQuery(HrDashboardFilters $filters): Builder
    {
        $query = CourseAssignment::query();

        if (! $filters->includeArchived) {
            $query->where('status', '!=', AssignmentStatus::Archived->value);
        }

        return $this->applyFilters($query, $filters);
    }

    /**
     * Base builder: eager-load user + course, exclude archived unless flag, apply filters, sort.
     *
     * @return Builder<CourseAssignment>
     */
    private function baseQuery(HrDashboardFilters $filters): Builder
    {
        // Qualify sort column with table prefix to avoid ambiguity when topCoursesByAssignments
        // adds a JOIN on courses (both tables have updated_at / completed_at).
        $sortColumn = 'course_assignments.'.$filters->sortBy;

        $query = CourseAssignment::query()
            ->with(['user', 'course'])
            ->orderBy($sortColumn, $filters->sortDir);

        if (! $filters->includeArchived) {
            $query->where('status', '!=', AssignmentStatus::Archived->value);
        }

        return $this->applyFilters($query, $filters);
    }

    /**
     * Apply user_id / course_id / status filters.
     *
     * HD5: if include_archived=false AND status=archived → effectively empty (applyFilters
     * adds where status='archived', but baseQuery already excludes archived — result is empty).
     *
     * @param  Builder<CourseAssignment>  $query
     * @return Builder<CourseAssignment>
     */
    private function applyFilters(Builder $query, HrDashboardFilters $filters): Builder
    {
        if ($filters->userId !== null) {
            $query->where('user_id', $filters->userId);
        }

        if ($filters->courseId !== null) {
            $query->where('course_id', $filters->courseId);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        return $query;
    }

    // -------------------------------------------------------------------------
    // Private: per-row enrichment
    // -------------------------------------------------------------------------

    /**
     * Enrich one CourseAssignment row into the chart-payload array (§В3).
     *
     * @return array<string, mixed>
     */
    private function enrichRow(CourseAssignment $assignment): array
    {
        // completion_rate: delegate to ProgressService (uses lesson_progress COUNT).
        // lessonIds are cached per course_id to avoid N repeated queries.
        $lessonIds = $this->getLessonIdsForCourse($assignment->course_id);
        $completionRate = $lessonIds->isEmpty()
            ? 0
            : $this->progressService->calcProgress($assignment);

        return [
            'id' => $assignment->id,
            'user' => $assignment->user ? [
                'id' => $assignment->user->id,
                'name' => $assignment->user->full_name,
                'email' => $assignment->user->email,
            ] : null,
            'course' => $assignment->course ? [
                'id' => $assignment->course->id,
                'title' => $assignment->course->title,
            ] : null,
            'completion_rate' => $completionRate,
            'status' => $assignment->status instanceof AssignmentStatus
                ? $assignment->status->value
                : $assignment->status,
            'due_date' => $assignment->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue($assignment),
            'avg_quiz_score' => $this->calcAvgQuizScore($assignment),
            'assigned_at' => $assignment->created_at?->toIso8601String(),
            'completed_at' => $assignment->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Overdue flag — computed in PHP for real-time accuracy (cron may lag ≤24h).
     *
     * status='overdue' always triggers the flag (cron already set it).
     * active statuses + due_date in the past also trigger it.
     *
     * HD2: due_date=null → false (no deadline means not overdue).
     */
    public function isOverdue(CourseAssignment $assignment): bool
    {
        $status = $assignment->status instanceof AssignmentStatus
            ? $assignment->status
            : AssignmentStatus::from((string) $assignment->status);

        if ($status === AssignmentStatus::Overdue) {
            return true;
        }

        // Only active statuses can be overdue dynamically.
        if (! in_array($status, [AssignmentStatus::Pending, AssignmentStatus::InProgress], strict: true)) {
            return false;
        }

        // HD2: no due_date → not overdue.
        if ($assignment->due_date === null) {
            return false;
        }

        return $assignment->due_date->isPast();
    }

    /**
     * Average quiz score for passed=true attempts on this assignment.
     *
     * HD3: returns null when no passed attempts exist (null = "hasn't passed yet").
     * Uses ix_quiz_attempts_assignment_passed composite index (S3.7 migration).
     */
    public function calcAvgQuizScore(CourseAssignment $assignment): ?int
    {
        $avg = QuizAttempt::where('assignment_id', $assignment->id)
            ->where('passed', true)
            ->avg('score_pct');

        return $avg !== null ? (int) round((float) $avg) : null;
    }

    // -------------------------------------------------------------------------
    // Private: chart payloads
    // -------------------------------------------------------------------------

    /**
     * ECharts pie payload for status distribution.
     *
     * Labels in RU, order: Ожидание / В процессе / Завершено / Просрочено.
     *
     * @param  array<string, int>  $kpi
     * @return array<string, mixed>
     */
    private function buildStatusPiePayload(array $kpi): array
    {
        return [
            'labels' => ['Ожидание', 'В процессе', 'Завершено', 'Просрочено'],
            'datasets' => [[
                'data' => [
                    $kpi['pending_count'],
                    $kpi['in_progress_count'],
                    $kpi['completed_count'],
                    $kpi['overdue_count'],
                ],
            ]],
            'meta' => ['type' => 'pie'],
        ];
    }

    /**
     * Top-10 courses by assignments count — horizontal bar ECharts payload.
     *
     * Pattern from S1.7 §Q5: LIMIT 11, PHP clips at 10 + «Другие» tail.
     *
     * @return array<string, mixed>
     */
    public function topCoursesByAssignments(HrDashboardFilters $filters, int $limit = 10): array
    {
        // Use aggregateQuery (no ORDER BY) to avoid PG SQLSTATE[42803] when GROUP BY
        // is combined with the ORDER BY that baseQuery() adds.
        $rows = $this->aggregateQuery($filters)
            ->join('courses as c', 'course_assignments.course_id', '=', 'c.id')
            ->selectRaw('c.title as course_title, COUNT(course_assignments.id) as assignment_count')
            ->groupBy('c.id', 'c.title')
            ->orderByRaw('COUNT(course_assignments.id) DESC')
            ->limit($limit + 1)
            ->get();

        $labels = [];
        $data = [];
        $othersCount = 0;

        foreach ($rows as $index => $row) {
            if ($index < $limit) {
                $labels[] = $row->course_title;
                $data[] = (int) $row->assignment_count;
            } else {
                // Row count = limit+1 → this is the overflow indicator.
                // We need the count of ALL remaining courses, not just this one row.
                // Because we only fetched limit+1 rows, we compute the remainder
                // as a second COUNT query on everything beyond the top-N.
                $topIds = $rows->slice(0, $limit)->pluck('course_title')->all();
                $othersCount = $this->aggregateQuery($filters)
                    ->join('courses as c', 'course_assignments.course_id', '=', 'c.id')
                    ->whereNotIn('c.title', $topIds)
                    ->count();

                $labels[] = 'Другие';
                $data[] = $othersCount;
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [['label' => 'Назначений', 'data' => $data]],
            'meta' => ['type' => 'bar', 'orientation' => 'horizontal'],
        ];
    }

    // -------------------------------------------------------------------------
    // Private: helpers
    // -------------------------------------------------------------------------

    /**
     * Get published lesson IDs for a course, cached per course_id.
     *
     * HD1 mitigation: one DB hit per unique course on the page (not per row).
     * Reduces query count from ~50 to ~27 per 25-row page.
     *
     * @return Collection<int, int>
     */
    private function getLessonIdsForCourse(int $courseId): Collection
    {
        if (! isset($this->lessonIdsCache[$courseId])) {
            $this->lessonIdsCache[$courseId] = Lesson::whereHas(
                'module',
                fn (Builder $q) => $q->where('course_id', $courseId)
            )
                ->where('is_published', true)
                ->pluck('id');
        }

        return $this->lessonIdsCache[$courseId];
    }
}
