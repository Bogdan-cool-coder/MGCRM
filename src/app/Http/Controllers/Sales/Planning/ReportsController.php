<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales\Planning;

use App\Domain\Sales\Data\BestManagerFilters;
use App\Domain\Sales\Data\ReportFilters;
use App\Domain\Sales\Services\Reports\BestManagerService;
use App\Domain\Sales\Services\Reports\ExpectedIncomeRegistryService;
use App\Domain\Sales\Services\Reports\IncomeScheduleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BestManagerReportRequest;
use App\Http\Requests\Sales\IncomeScheduleReportRequest;
use App\Http\Requests\Sales\RegistryReportRequest;
use App\Http\Resources\Sales\BestManagerResource;
use App\Http\Resources\Sales\IncomeScheduleResource;
use App\Http\Resources\Sales\RegistryReportResource;

/**
 * Thin controller for the Sales Analytics reports (R1/R2/R3, contract
 * §6.4/§6.5/§6.6). Reads are visibility-scoped inside the aggregator Service,
 * not gated by a permission (contract §8.2 — any authed user calls these, the
 * aggregator scopes rows).
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ExpectedIncomeRegistryService $registryService,
        private readonly IncomeScheduleService $incomeScheduleService,
        private readonly BestManagerService $bestManagerService,
    ) {}

    /**
     * GET /api/reports/registry — R1 «Реестр + Дожим»
     */
    public function registry(RegistryReportRequest $request): RegistryReportResource
    {
        $filters = ReportFilters::forMonth(
            year: $request->integer('year'),
            month: $request->integer('month'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            userId: $request->filled('manager_id') ? $request->integer('manager_id') : null,
            productGroupId: $request->filled('product_group_id') ? $request->integer('product_group_id') : null,
        );

        return new RegistryReportResource($this->registryService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/income-schedule — R2 «График НП»
     */
    public function incomeSchedule(IncomeScheduleReportRequest $request): IncomeScheduleResource
    {
        $filters = ReportFilters::forMonth(
            year: $request->integer('year'),
            month: $request->integer('month'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            userId: $request->filled('manager_id') ? $request->integer('manager_id') : null,
        );

        return new IncomeScheduleResource($this->incomeScheduleService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/best-manager — R3 «Лучший менеджер»
     */
    public function bestManager(BestManagerReportRequest $request): BestManagerResource
    {
        $filters = BestManagerFilters::forYear(
            year: $request->integer('year'),
            mode: (string) $request->input('mode', 'standard'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return new BestManagerResource($this->bestManagerService->build($filters, $request->user()));
    }
}
