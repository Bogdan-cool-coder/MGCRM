<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Services\DealKpiService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Sales\IndexDealRequest;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller for the Deals-page KPI chip bar (SalesFunnel-spec §5.1).
 *
 * GET /api/deals/kpi — funnel-wide KPI counters. Accepts the IDENTICAL filter set
 * as GET /api/deals (it reuses IndexDealRequest, so the same validation +
 * viewAny authorisation gate the list does), but ignores pagination: the chips
 * are counted across the WHOLE filtered, visibility-scoped funnel rather than a
 * single list page.
 *
 * All counting is delegated to DealKpiService, which reuses DealService's
 * scope-aware filter path so the chips match the list byte-for-byte.
 */
class DealKpiController extends Controller
{
    public function __construct(
        private readonly DealKpiService $service,
    ) {}

    public function __invoke(IndexDealRequest $request): JsonResponse
    {
        // The request already authorised viewAny(Deal) + validated the full filter
        // set; only validated keys reach the service. pagination keys (per_page /
        // view / page) are simply not read by the KPI aggregate.
        $stats = $this->service->forFunnel(
            $request->validated(),
            $this->scope($request),
            $request->user(),
        );

        return response()->json(['data' => $stats]);
    }

    /**
     * Row-level visibility scope, resolved the same way DealController does:
     * read the ResolveVisibility attribute, falling back to the most restrictive
     * Own scope when absent (fail-closed). Deals use department-subtree scoping —
     * never the Contacts role-based shortcut.
     */
    private function scope(IndexDealRequest $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
