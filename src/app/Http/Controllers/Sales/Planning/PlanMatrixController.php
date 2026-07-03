<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales\Planning;

use App\Domain\Sales\Data\CopyPeriodData;
use App\Domain\Sales\Data\PlanMatrixQuery;
use App\Domain\Sales\Data\UpsertCellData;
use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BulkUpsertCellsRequest;
use App\Http\Requests\Sales\CopyPreviousPlanRequest;
use App\Http\Requests\Sales\PlanMatrixRequest;
use App\Http\Resources\Sales\PlanCellResource;
use App\Http\Resources\Sales\PlanMatrixResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin controller for the plan matrix (P-1/P-2/P-3, contract §6.1-6.3).
 * Reads (index) are visibility-scoped in the Service, not gated; writes
 * (bulkUpsert/copyPrevious) sit behind `can:plans.manage` route middleware.
 */
class PlanMatrixController extends Controller
{
    public function __construct(
        private readonly PlanTargetService $planTargetService,
    ) {}

    /**
     * GET /api/plans/matrix
     */
    public function index(PlanMatrixRequest $request): PlanMatrixResource
    {
        $query = new PlanMatrixQuery(
            metric: PlanMetric::from($request->string('metric')->toString()),
            scopeType: PlanScopeType::from($request->string('scope_type')->toString()),
            layer: PlanLayer::from($request->string('layer')->toString()),
            year: $request->integer('year'),
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            month: $request->filled('month') ? $request->integer('month') : null,
        );

        return new PlanMatrixResource($this->planTargetService->buildMatrix($query, $request->user()));
    }

    /**
     * POST /api/plans/cells
     */
    public function bulkUpsert(BulkUpsertCellsRequest $request): AnonymousResourceCollection
    {
        $cells = array_map(
            static fn (array $row): UpsertCellData => UpsertCellData::fromArray($row),
            $request->validated('cells'),
        );

        $affected = $this->planTargetService->upsertCells($cells, $request->user());

        return PlanCellResource::collection($affected);
    }

    /**
     * POST /api/plans/copy-previous
     */
    public function copyPrevious(CopyPreviousPlanRequest $request): JsonResponse
    {
        $data = new CopyPeriodData(
            metric: PlanMetric::from($request->string('metric')->toString()),
            layer: PlanLayer::from($request->string('layer')->toString()),
            scopeType: PlanScopeType::from($request->string('scope_type')->toString()),
            fromYear: $request->integer('from_year'),
            toYear: $request->integer('to_year'),
            fromMonth: $request->filled('from_month') ? $request->integer('from_month') : null,
            toMonth: $request->filled('to_month') ? $request->integer('to_month') : null,
            pipelineId: $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
        );

        $created = $this->planTargetService->copyPreviousPeriod($data, $request->user());

        return response()->json(['data' => ['created' => $created]]);
    }
}
