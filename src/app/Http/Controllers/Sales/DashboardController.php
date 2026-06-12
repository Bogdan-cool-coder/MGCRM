<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Data\DashboardFilters;
use App\Domain\Sales\Services\SalesDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Sales\DashboardRequest;
use App\Http\Resources\Sales\DashboardResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin controller for the S1.7 Sales Dashboard (ARCHITECTURE.md §1).
 * Two endpoints: JSON aggregation and xlsx export.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly SalesDashboardService $service,
    ) {}

    public function dashboard(DashboardRequest $request): DashboardResource
    {
        $filters = DashboardFilters::fromRequest($request);
        $data = $this->service->getDashboardData($filters, $request->user());

        return new DashboardResource($data);
    }

    public function export(DashboardRequest $request): StreamedResponse
    {
        $filters = DashboardFilters::fromRequest($request);
        $user = $request->user();
        $xlsx = $this->service->buildXlsx($filters, $user);

        return response()->streamDownload(
            function () use ($xlsx): void {
                echo $xlsx;
            },
            'sales-dashboard.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="sales-dashboard.xlsx"',
                'Cache-Control' => 'no-store',
            ]
        );
    }

    // ---- Private ----

    private function scope(Request $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
