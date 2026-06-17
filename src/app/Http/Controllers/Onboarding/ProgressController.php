<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Data\HrDashboardFilters;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\OnboardingDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\HrProgressRequest;
use App\Http\Resources\Onboarding\HrProgressResource;
use App\Http\Resources\Onboarding\HrProgressSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HR-dashboard progress controller (S3.7).
 *
 * Thin controller: FormRequest → authorize → Service → Resource.
 * Authorization via AssignmentPolicy::viewAny() — admin/director only (403 for others).
 */
class ProgressController extends Controller
{
    public function __construct(
        private readonly OnboardingDashboardService $dashboardService,
    ) {}

    /**
     * Paginated list of assignments with per-row completion_rate / overdue / avg_score.
     *
     * GET /api/admin/onboarding/progress
     */
    public function index(HrProgressRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourseAssignment::class);

        $filters = HrDashboardFilters::fromRequest($request);
        $perPage = $request->filled('per_page') ? (int) $request->input('per_page') : 25;

        $paginator = $this->dashboardService->getHrDashboard($filters, $perPage);

        /** @var AnonymousResourceCollection $collection */
        $collection = HrProgressResource::collection($paginator);

        // Append filter state and generation timestamp to the paginator meta.
        $collection->additional([
            'meta' => array_merge(
                // HrProgressResource::collection wraps paginator meta automatically;
                // we merge extra keys into the same meta block via additional().
                [],
                [
                    'filters' => [
                        'user_id' => $filters->userId,
                        'course_id' => $filters->courseId,
                        'status' => $filters->status,
                        'include_archived' => $filters->includeArchived,
                    ],
                    'generated_at' => now()->toIso8601String(),
                ]
            ),
        ]);

        return $collection;
    }

    /**
     * Summary: 4 KPI counters + ECharts pie + ECharts horizontal bar (top-10 courses).
     *
     * GET /api/admin/onboarding/progress/summary
     */
    public function summary(HrProgressRequest $request): HrProgressSummaryResource
    {
        $this->authorize('viewAny', CourseAssignment::class);

        $filters = HrDashboardFilters::fromRequest($request);
        $data = $this->dashboardService->getSummary($filters);

        return HrProgressSummaryResource::make($data);
    }
}
