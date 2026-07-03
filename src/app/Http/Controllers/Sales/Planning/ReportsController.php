<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales\Planning;

use App\Domain\Sales\Data\BestManagerFilters;
use App\Domain\Sales\Data\ConversionReportFilters;
use App\Domain\Sales\Data\ProductIncomeFilters;
use App\Domain\Sales\Data\ReportFilters;
use App\Domain\Sales\Data\StageConversionFilters;
use App\Domain\Sales\Data\TaskMatrixFilters;
use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Services\Reports\BestManagerService;
use App\Domain\Sales\Services\Reports\ConversionReportService;
use App\Domain\Sales\Services\Reports\ExpectedIncomeRegistryService;
use App\Domain\Sales\Services\Reports\IncomeScheduleService;
use App\Domain\Sales\Services\Reports\ProductIncomeService;
use App\Domain\Sales\Services\Reports\ReportExportService;
use App\Domain\Sales\Services\Reports\StageConversionService;
use App\Domain\Sales\Services\Reports\TaskMatrixService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BestManagerReportRequest;
use App\Http\Requests\Sales\ConversionReportRequest;
use App\Http\Requests\Sales\IncomeScheduleReportRequest;
use App\Http\Requests\Sales\ProductIncomeReportRequest;
use App\Http\Requests\Sales\RegistryReportRequest;
use App\Http\Requests\Sales\StageConversionReportRequest;
use App\Http\Requests\Sales\TaskMatrixReportRequest;
use App\Http\Resources\Sales\BestManagerResource;
use App\Http\Resources\Sales\ConversionReportResource;
use App\Http\Resources\Sales\IncomeScheduleResource;
use App\Http\Resources\Sales\ProductIncomeResource;
use App\Http\Resources\Sales\RegistryReportResource;
use App\Http\Resources\Sales\StageConversionResource;
use App\Http\Resources\Sales\TaskMatrixResource;
use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin controller for the Sales Analytics reports (R1/R2/R3/R4/R5/R6, contract
 * §6.4/§6.5/§6.6/§6.7/§6.8/§6.9). Reads are visibility-scoped inside the
 * aggregator Service, not gated by a permission (contract §8.2 — any authed
 * user calls these, the aggregator scopes rows). Each report also has an
 * `*Export` action (Ф5 §Excel export) streaming a .xlsx built by
 * `ReportExportService` off the SAME aggregator + filters as its JSON sibling.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ExpectedIncomeRegistryService $registryService,
        private readonly IncomeScheduleService $incomeScheduleService,
        private readonly BestManagerService $bestManagerService,
        private readonly TaskMatrixService $taskMatrixService,
        private readonly ConversionReportService $conversionReportService,
        private readonly StageConversionService $stageConversionService,
        private readonly ProductIncomeService $productIncomeService,
        private readonly ReportExportService $exportService,
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
     * GET /api/reports/registry/export — R1 xlsx export
     */
    public function registryExport(RegistryReportRequest $request): StreamedResponse
    {
        $filters = ReportFilters::forMonth(
            year: $request->integer('year'),
            month: $request->integer('month'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            userId: $request->filled('manager_id') ? $request->integer('manager_id') : null,
            productGroupId: $request->filled('product_group_id') ? $request->integer('product_group_id') : null,
        );

        return $this->streamXlsx('registry', fn () => $this->exportService->exportRegistry($filters, $request->user()));
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
     * GET /api/reports/income-schedule/export — R2 xlsx export
     */
    public function incomeScheduleExport(IncomeScheduleReportRequest $request): StreamedResponse
    {
        $filters = ReportFilters::forMonth(
            year: $request->integer('year'),
            month: $request->integer('month'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            userId: $request->filled('manager_id') ? $request->integer('manager_id') : null,
        );

        return $this->streamXlsx('income-schedule', fn () => $this->exportService->exportIncomeSchedule($filters, $request->user()));
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

    /**
     * GET /api/reports/best-manager/export — R3 xlsx export
     */
    public function bestManagerExport(BestManagerReportRequest $request): StreamedResponse
    {
        $filters = BestManagerFilters::forYear(
            year: $request->integer('year'),
            mode: (string) $request->input('mode', 'standard'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return $this->streamXlsx('best-manager', fn () => $this->exportService->exportBestManager($filters, $request->user()));
    }

    /**
     * GET /api/reports/task-matrix — R4 «Закрытие задач»
     */
    public function taskMatrix(TaskMatrixReportRequest $request): TaskMatrixResource
    {
        $filters = TaskMatrixFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            kind: $request->filled('kind') ? $request->string('kind')->toString() : null,
        );

        return new TaskMatrixResource($this->taskMatrixService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/task-matrix/export — R4 xlsx export
     */
    public function taskMatrixExport(TaskMatrixReportRequest $request): StreamedResponse
    {
        $filters = TaskMatrixFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            kind: $request->filled('kind') ? $request->string('kind')->toString() : null,
        );

        return $this->streamXlsx('task-matrix', fn () => $this->exportService->exportTaskMatrix($filters, $request->user()));
    }

    /**
     * GET /api/reports/conversions — R5 «Конверсии»
     */
    public function conversions(ConversionReportRequest $request): ConversionReportResource
    {
        $filters = ConversionReportFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            scopeType: PlanScopeType::from($request->string('scope_type', PlanScopeType::User->value)->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return new ConversionReportResource($this->conversionReportService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/conversions/export — R5 xlsx export
     */
    public function conversionsExport(ConversionReportRequest $request): StreamedResponse
    {
        $filters = ConversionReportFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            scopeType: PlanScopeType::from($request->string('scope_type', PlanScopeType::User->value)->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return $this->streamXlsx('conversions', fn () => $this->exportService->exportConversions($filters, $request->user()));
    }

    /**
     * GET /api/reports/stage-conversions — honest stage-to-stage conversions
     * (improvement #3, contract §6.8 `stage` block as a standalone endpoint).
     */
    public function stageConversions(StageConversionReportRequest $request): StageConversionResource
    {
        $filters = StageConversionFilters::make(
            pipelineId: $request->integer('pipeline_id'),
            year: $request->filled('year') ? $request->integer('year') : null,
            month: $request->filled('month') ? $request->integer('month') : null,
        );

        return new StageConversionResource($this->stageConversionService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/product-income — R6 «Поступления по линейкам»
     */
    public function productIncome(ProductIncomeReportRequest $request): ProductIncomeResource
    {
        $filters = ProductIncomeFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return new ProductIncomeResource($this->productIncomeService->build($filters, $request->user()));
    }

    /**
     * GET /api/reports/product-income/export — R6 xlsx export
     */
    public function productIncomeExport(ProductIncomeReportRequest $request): StreamedResponse
    {
        $filters = ProductIncomeFilters::make(
            year: $request->integer('year'),
            layer: PlanLayer::from($request->string('layer')->toString()),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        return $this->streamXlsx('product-income', fn () => $this->exportService->exportProductIncome($filters, $request->user()));
    }

    /**
     * Shared streamDownload wrapper (mirrors DashboardController::export) —
     * one Content-Type/Content-Disposition/no-store convention for every
     * report's .xlsx download so the FE never has to special-case a report.
     *
     * @param  Closure(): string  $buildXlsx
     */
    private function streamXlsx(string $slug, Closure $buildXlsx): StreamedResponse
    {
        $xlsx = $buildXlsx();

        return response()->streamDownload(
            function () use ($xlsx): void {
                echo $xlsx;
            },
            "report-{$slug}.xlsx",
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"report-{$slug}.xlsx\"",
                'Cache-Control' => 'no-store',
            ]
        );
    }
}
