<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\BulkDealService;
use App\Domain\Sales\Services\DealExportService;
use App\Domain\Sales\Services\DealMoveService;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVisibility;
use App\Http\Requests\Sales\BulkDealActionRequest;
use App\Http\Requests\Sales\BulkDealDeleteRequest;
use App\Http\Requests\Sales\IndexDealRequest;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin Deal controller (ARCHITECTURE.md §1). The ?view query selects list vs
 * board. Stage changes go exclusively through move() — never update().
 */
class DealController extends Controller
{
    public function __construct(
        private readonly DealService $service,
        private readonly DealMoveService $mover,
        private readonly BulkDealService $bulk,
        private readonly DealExportService $exporter,
        private readonly ActivityService $activities,
    ) {}

    public function index(IndexDealRequest $request): AnonymousResourceCollection|JsonResponse
    {
        // The request already authorised viewAny + validated the full filter set;
        // only validated keys reach the service (unknown query params are ignored).
        $scope = $this->scope($request);
        $filters = $request->validated();

        if (($filters['view'] ?? null) === 'board') {
            return $this->board($request, $scope, $filters);
        }

        $deals = $this->service->list(
            $filters,
            $scope,
            $request->user(),
            (int) ($filters['per_page'] ?? 25),
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

        $deal->load([
            'pipeline:id,name,kind',
            'stage',
            // Highest reached stage for the max_stage key action (ref shape).
            'maxStage:id,name,color',
            'company:id,name',
            'owner:id,full_name',
            'nextTask',
            'products.product:id,code,name',
            'products.plan:id,name',
            'dealContacts.contact:id,full_name,position,email,phone',
        ]);

        // Derived key-action dates (last presentation / touch / event) from the
        // Activity timeline — stamped onto the model for the DealResource. Only
        // computed on the single-deal card (not on list/board payloads).
        $deal->setAttribute(
            'key_action_dates',
            $this->activities->keyActionDatesForDeal((int) $deal->id),
        );

        // Six «Активность»-tab metrics — only computed on the single-deal card.
        $deal->setAttribute('metrics_payload', $this->service->metricsFor($deal));

        return DealResource::make($deal);
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

    /**
     * PATCH /api/deals/bulk — mass edit a set of deals (board toolbar). The
     * operation + its payload are validated by the request; BulkDealService
     * authorises every deal individually (all-or-nothing 403) and routes each
     * through the audited single-item path. Returns the processed count.
     */
    public function bulkUpdate(BulkDealActionRequest $request): JsonResponse
    {
        $processed = $this->bulk->apply(
            $request->dealIds(),
            $request->validated('operation'),
            $request->payload(),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'operation' => $request->validated('operation'),
                'processed' => $processed,
            ],
        ]);
    }

    /**
     * DELETE /api/deals/bulk — mass soft-delete. BulkDealService authorises every
     * deal under the delete ability (all-or-nothing 403). Returns the count.
     */
    public function bulkDestroy(BulkDealDeleteRequest $request): JsonResponse
    {
        $deleted = $this->bulk->delete($request->dealIds(), $request->user());

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    /**
     * GET /api/deals/export — XLSX of the filtered, visibility-scoped deal list.
     * Honours the SAME validated filter set as the list/board so the file always
     * matches exactly what is on screen, plus the user's row-level scope.
     */
    public function export(IndexDealRequest $request): StreamedResponse
    {
        $xlsx = $this->exporter->buildXlsx(
            $request->validated(),
            $this->scope($request),
            $request->user(),
        );

        return response()->streamDownload(
            static function () use ($xlsx): void {
                echo $xlsx;
            },
            'deals.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="deals.xlsx"',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    /**
     * POST /api/deals/{deal}/kp-sent — mark the КП (commercial proposal) as sent.
     * Stamps kp_sent_at = now() and appends a kp_sent log row. Returns the deal
     * with its key-action header refreshed.
     */
    public function markKpSent(Request $request, Deal $deal): JsonResource
    {
        $this->authorize('update', $deal);

        $this->service->markKpSent($deal, $request->user());

        return $this->keyActionResponse($deal);
    }

    /**
     * POST /api/deals/{deal}/contract-sent — mark the contract as sent. Stamps
     * contract_sent_at = now() and appends a contract_sent log row. Returns the
     * deal with its key-action header refreshed. (Contracts can also stamp this
     * automatically when a contract Document reaches `submitted`.)
     */
    public function markContractSent(Request $request, Deal $deal): JsonResource
    {
        $this->authorize('update', $deal);

        $this->service->markContractSent($deal, $request->user());

        return $this->keyActionResponse($deal);
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
     * Render a deal after a key-action mutation (kp-sent / contract-sent): reload
     * the display + maxStage relations and re-derive the key-action dates so the
     * returned card header is fully current. Same shape as show().
     */
    private function keyActionResponse(Deal $deal): JsonResource
    {
        $deal->load([
            'pipeline:id,name,kind',
            'stage',
            'maxStage:id,name,color',
            'company:id,name',
            'owner:id,full_name',
        ]);

        $deal->setAttribute(
            'key_action_dates',
            $this->activities->keyActionDatesForDeal((int) $deal->id),
        );

        return DealResource::make($deal);
    }

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

    /**
     * @param  array<string, mixed>  $filters
     */
    private function board(Request $request, VisibilityScope $scope, array $filters): JsonResponse
    {
        $pipelineId = isset($filters['pipeline_id'])
            ? (int) $filters['pipeline_id']
            : $this->service->defaultSalesPipelineId();

        if ($pipelineId === null) {
            return response()->json(['message' => 'No sales pipeline available.'], 404);
        }

        $board = $this->service->board(
            $pipelineId,
            $scope,
            $request->user(),
            $filters,
        );

        $columns = [];
        foreach ($board['columns'] as $stageId => $column) {
            $columns[$stageId] = [
                'stage_id' => $column['stage_id'],
                'total' => $column['total'],
                'sum_amount' => $column['sum_amount'], // base currency (kopecks)
                // false ⇒ this column has a currency with no rate, so sum_amount is
                // partial; the frontend shows native amounts_by_currency without "≈".
                'rate_available' => $column['rate_available'],
                'amounts_by_currency' => (object) $column['amounts_by_currency'], // native, kopecks
                'deals' => DealCardResource::collection($column['deals']),
            ];
        }

        return response()->json([
            'pipeline' => [
                'id' => $board['pipeline']->id,
                'name' => $board['pipeline']->name,
                'kind' => $board['pipeline']->kind?->value,
            ],
            'base_currency' => $board['base_currency'],
            'multi_currency_warning' => $board['multi_currency_warning'],
            'stages' => PipelineStageResource::collection($board['stages']),
            // Hidden-by-default stages (funnel order) with scope+filter-aware deal
            // counts — the filter panel renders a reveal toggle per entry.
            'hidden_stages' => $board['hidden_stages'],
            'columns' => $columns,
        ]);
    }

    private function scope(Request $request): VisibilityScope
    {
        $scope = $request->attributes->get(ResolveVisibility::ATTRIBUTE);

        return $scope instanceof VisibilityScope ? $scope : VisibilityScope::Own;
    }
}
