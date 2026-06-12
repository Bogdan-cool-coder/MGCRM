<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Services\ManagerKpiService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\KpiRequest;
use App\Http\Resources\Sales\ActivityFeedItemResource;
use App\Http\Resources\Sales\KpiResource;
use App\Http\Resources\Sales\MeProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin controller for the S1.8 Manager Cabinet endpoints.
 * All business logic delegated to ManagerKpiService / ActivityService.
 */
class ManagerCabinetController extends Controller
{
    public function __construct(
        private readonly ManagerKpiService $kpiService,
        private readonly ActivityService $activityService,
    ) {}

    /**
     * GET /api/me/profile
     * Returns extended profile: name, job title, department, manager, subordinates.
     */
    public function profile(Request $request): MeProfileResource
    {
        $userId = $request->query('user_id') ? (int) $request->query('user_id') : null;
        $target = $this->kpiService->resolveTargetUser($request->user(), $userId);
        $data = $this->kpiService->getProfile($target);

        return new MeProfileResource($data);
    }

    /**
     * GET /api/me/kpi
     * Returns KPI payload: meta / personal (score_pct, income, FTM) / team comparison.
     */
    public function kpi(KpiRequest $request): KpiResource
    {
        $filters = KpiFilters::fromRequest($request);
        $data = $this->kpiService->getKpiData($filters, $request->user());

        return new KpiResource($data);
    }

    /**
     * GET /api/me/activity-feed
     * Paginated activity feed scoped to the authenticated manager (or requested user).
     */
    public function activityFeed(KpiRequest $request): AnonymousResourceCollection
    {
        $filters = KpiFilters::fromRequest($request);
        $target = $this->kpiService->resolveTargetUser($request->user(), $filters->userId);

        $perPage = $request->integer('per_page', 25);

        $feedFilters = [
            'kind' => $request->string('kind', 'all')->toString(),
            'from' => $filters->dateFrom,
            'to' => $filters->dateTo,
            'ftm_only' => $request->boolean('ftm_only', false),
        ];

        $paginator = $this->activityService->feedForUser($target->id, $feedFilters, $perPage);

        // LengthAwarePaginator → AnonymousResourceCollection automatically emits
        // meta.current_page / meta.last_page / meta.per_page / meta.total
        // (standard Laravel paginator resource response, plan §В3).
        return ActivityFeedItemResource::collection($paginator);
    }
}
