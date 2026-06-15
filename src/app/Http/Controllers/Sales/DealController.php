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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
        $updated = $this->service->update($deal, $request->validated(), $request->user());

        return DealResource::make($updated->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name']));
    }

    public function destroy(Request $request, Deal $deal): Response
    {
        $this->authorize('delete', $deal);

        $this->service->delete($deal);

        return response()->noContent();
    }

    public function archive(Request $request, Deal $deal): JsonResource
    {
        $this->authorize('update', $deal);

        $archived = $this->service->archive($deal);

        return DealResource::make($archived->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name']));
    }

    public function unarchive(Request $request, Deal $deal): JsonResource
    {
        $this->authorize('update', $deal);

        $restored = $this->service->unarchive($deal);

        return DealResource::make($restored->load(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name']));
    }

    /** Cache TTL for replaying an idempotent move result (HD1, Q1: 24h). */
    private const IDEMPOTENCY_TTL_SECONDS = 86_400;

    public function move(MoveDealRequest $request, Deal $deal): JsonResponse
    {
        // HD1 (S1.9): request-level idempotency. The move is already
        // state-idempotent (no-op + row-lock); an optional Idempotency-Key adds
        // replay safety against retried POSTs — the same key returns the cached
        // result without running a second move (no duplicate DealStageHistory).
        $cacheKey = $this->idempotencyCacheKey($request, $deal);

        if ($cacheKey !== null && ($cached = Cache::get($cacheKey)) !== null) {
            return $this->moveResponse($cached['deal_id']);
        }

        $moved = $this->mover->move(
            $deal,
            (int) $request->validated('to_stage_id'),
            $request->user()->id,
            $request->validated('lost_reason'),
            $request->validated('lost_reason_id') !== null
                ? (int) $request->validated('lost_reason_id')
                : null,
        );

        if ($cacheKey !== null) {
            Cache::put($cacheKey, [
                'deal_id' => $moved->id,
            ], self::IDEMPOTENCY_TTL_SECONDS);
        }

        return $this->moveResponse($moved->id);
    }

    // ---- Private ----

    /**
     * Build the idempotency cache key from the Idempotency-Key header, scoped to
     * the deal (Q1: `move:{deal_id}:{key}`). Returns null when the header is
     * absent — callers without a key keep the plain state-idempotent behaviour.
     */
    private function idempotencyCacheKey(Request $request, Deal $deal): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return "move:{$deal->id}:".trim($key);
    }

    /**
     * Render the standard move response (clean DealResource, 200) from a deal id,
     * reloading the display relations. Shared by the fresh-move and cached-replay
     * paths so both return the identical shape. The soft won_gate_warning is gone
     * (S2.8): the gate is now a hard 409 raised inside DealMoveService.
     */
    private function moveResponse(int $dealId): JsonResponse
    {
        $deal = Deal::query()
            ->with(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name'])
            ->findOrFail($dealId);

        return DealResource::make($deal)->response();
    }

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
