<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealMoveService;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Sales\MoveDealRequest;
use App\Http\Requests\Sales\StoreDealRequest;
use App\Http\Requests\Sales\UpdateDealRequest;
use App\Http\Resources\Sales\DealCardResource;
use App\Http\Resources\Sales\DealResource;
use App\Http\Resources\Sales\PipelineStageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin Deal controller (ARCHITECTURE.md §1). The ?view query selects list vs
 * board. Stage changes go exclusively through move() — never update().
 */
class DealController extends Controller
{
    public function __construct(
        private readonly DealService $service,
        private readonly DealMoveService $mover,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $this->authorize('viewAny', Deal::class);

        $scope = $this->scope($request);

        if ($request->query('view') === 'board') {
            return $this->board($request, $scope);
        }

        $deals = $this->service->list(
            $request->query(),
            $scope,
            $request->user(),
            (int) $request->query('per_page', 25),
        );

        return DealResource::collection($deals);
    }

    public function store(StoreDealRequest $request): JsonResource
    {
        $deal = $this->service->create($request->validated(), $request->user());

        return DealResource::make($deal->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name']));
    }

    public function show(Request $request, Deal $deal): JsonResource
    {
        $this->authorize('view', $deal);

        return DealResource::make($deal->load([
            'pipeline:id,name,kind',
            'stage',
            'company:id,name',
            'owner:id,full_name',
            'products.product:id,code,name',
            'products.plan:id,name',
            'dealContacts.contact:id,full_name,position,email,phone',
        ]));
    }

    public function update(UpdateDealRequest $request, Deal $deal): JsonResource
    {
        $updated = $this->service->update($deal, $request->validated());

        return DealResource::make($updated->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name']));
    }

    public function destroy(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('delete', $deal);

        $this->service->delete($deal);

        return response()->json(['message' => 'Deal deleted.'], 200);
    }

    public function move(MoveDealRequest $request, Deal $deal): JsonResponse
    {
        $result = $this->mover->move(
            $deal,
            (int) $request->validated('to_stage_id'),
            $request->user()->id,
            $request->validated('lost_reason'),
            $request->validated('lost_reason_id') !== null
                ? (int) $request->validated('lost_reason_id')
                : null,
        );

        /** @var Deal $moved */
        $moved = $result['deal'];

        return DealResource::make(
            $moved->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name'])
        )->additional(['won_gate_warning' => $result['won_gate_warning']])
            ->response();
    }

    // ---- Private ----

    private function board(Request $request, VisibilityScope $scope): JsonResponse
    {
        $pipelineId = $request->filled('pipeline_id')
            ? (int) $request->query('pipeline_id')
            : $this->service->defaultSalesPipelineId();

        if ($pipelineId === null) {
            return response()->json(['message' => 'No sales pipeline available.'], 404);
        }

        $board = $this->service->board(
            $pipelineId,
            $scope,
            $request->user(),
        );

        $columns = [];
        foreach ($board['columns'] as $stageId => $column) {
            $columns[$stageId] = [
                'stage_id' => $column['stage_id'],
                'total' => $column['total'],
                'sum_amount' => $column['sum_amount'],
                'deals' => DealCardResource::collection($column['deals']),
            ];
        }

        return response()->json([
            'pipeline' => [
                'id' => $board['pipeline']->id,
                'name' => $board['pipeline']->name,
                'kind' => $board['pipeline']->kind?->value,
            ],
            'stages' => PipelineStageResource::collection($board['stages']),
            'columns' => $columns,
        ]);
    }

    private function scope(Request $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
