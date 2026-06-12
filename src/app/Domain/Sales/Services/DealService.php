<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DealService — all Deal CRUD/list/board logic. stage_id is deliberately NOT
 * mutated here: stage changes go exclusively through DealMoveService::move().
 */
class DealService
{
    /** Number of cards returned per Kanban column (first page). */
    private const BOARD_COLUMN_LIMIT = 30;

    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

    /**
     * Paginated, visibility-scoped list of deals.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, VisibilityScope $scope, User $user, int $perPage = 25): LengthAwarePaginator
    {
        return $this->scopedQuery($scope, $user)
            ->with(['pipeline:id,name,kind', 'stage', 'company:id,name', 'owner:id,full_name'])
            ->when(isset($filters['pipeline_id']), fn (Builder $q) => $q->where('pipeline_id', $filters['pipeline_id']))
            ->when(isset($filters['stage_id']), fn (Builder $q) => $q->where('stage_id', $filters['stage_id']))
            ->when(isset($filters['owner_id']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner_id']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters): void {
                $q->where('title', 'like', '%'.$filters['q'].'%');
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Resolve the default sales pipeline (first by sort_order) for board views
     * when the request omits pipeline_id. Null only if no sales pipeline exists.
     */
    public function defaultSalesPipelineId(): ?int
    {
        return Pipeline::query()
            ->sales()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Kanban board: stages of a pipeline + deals grouped by stage with totals.
     *
     * @return array<string, mixed>
     */
    public function board(int $pipelineId, VisibilityScope $scope, User $user): array
    {
        $pipeline = Pipeline::query()->with('stages')->findOrFail($pipelineId);

        $columns = [];
        foreach ($pipeline->stages as $stage) {
            $base = $this->scopedQuery($scope, $user)
                ->where('pipeline_id', $pipelineId)
                ->where('stage_id', $stage->id);

            $total = (clone $base)->count();
            $sumAmount = (int) (clone $base)->sum('amount');

            $deals = (clone $base)
                ->with(['company:id,name', 'owner:id,full_name'])
                ->orderByDesc('created_at')
                ->limit(self::BOARD_COLUMN_LIMIT)
                ->get();

            $columns[(string) $stage->id] = [
                'stage_id' => $stage->id,
                'total' => $total,
                'sum_amount' => $sumAmount,
                'deals' => $deals,
            ];
        }

        return [
            'pipeline' => $pipeline,
            'stages' => $pipeline->stages,
            'columns' => $columns,
        ];
    }

    /**
     * Create a deal on an existing company. Stage is forced to the first stage
     * of the pipeline (by sort_order); department is stamped from the owner.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Deal
    {
        $data['owner_user_id'] ??= $creator->id;

        $owner = $data['owner_user_id'] === $creator->id
            ? $creator
            : User::find($data['owner_user_id']);
        $data['department_id'] ??= $owner?->department_id;

        $pipeline = Pipeline::query()->with('stages')->findOrFail($data['pipeline_id']);
        $firstStage = $pipeline->stages
            ->where('hidden_by_default', false)
            ->where('is_lost', false)
            ->where('is_won', false)
            ->sortBy('sort_order')
            ->first();

        if ($firstStage === null) {
            throw ValidationException::withMessages([
                'pipeline_id' => 'Pipeline has no stages.',
            ]);
        }

        $data['stage_id'] = $firstStage->id;
        $data['stage_changed_at'] = now();

        $deal = Deal::create($data);

        // Record creation event in stage history (from_stage_id = null → creation).
        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $firstStage->id,
            'user_id' => $creator->id,
            'created_at' => $deal->created_at,
        ]);

        return $deal;
    }

    /**
     * Cross-domain contract for the Inbox context (S1.9). Creates a Deal on an
     * already-resolved Company at an EXPLICIT stage (no "first non-won" lookup —
     * the caller, InboundRoutingService, resolves the target stage: channel
     * default or sales `code='new'`/fallback). The owner is the channel's static
     * default_owner_id; the department is stamped from that owner.
     *
     * Writes the creation row in DealStageHistory (from_stage_id = null) and
     * emits the DealCreated event — the stable contract the Notification /
     * automation / outbound-webhook domains subscribe to (no senders ship yet).
     *
     * NB: unlike create(), this is internal (no creator User) — there is no
     * authenticated actor for an anonymous inbound submission, so the history
     * user_id is null. Title/source come from $opts (resolved by the caller).
     *
     * $ownerId is typed nullable for contract fidelity with the channel's
     * nullable default_owner_id, but `deals.owner_user_id` is NOT NULL: the
     * caller (InboundRoutingService) must resolve a concrete owner (channel
     * default, or its own fallback) before calling. Round-robin (M7) overrides
     * the static owner later via the DealCreated event.
     *
     * @param  array{title?: string, currency?: string, source?: string, tags?: list<string>, extra_fields?: array<string, mixed>}  $opts
     */
    public function createInbound(
        Company $company,
        array $opts,
        ?int $ownerId,
        int $pipelineId,
        int $stageId,
    ): Deal {
        return DB::transaction(function () use ($company, $opts, $ownerId, $pipelineId, $stageId): Deal {
            $owner = $ownerId !== null ? User::find($ownerId) : null;

            $deal = Deal::create([
                'pipeline_id' => $pipelineId,
                'stage_id' => $stageId,
                'company_id' => $company->id,
                'title' => $opts['title'] ?? "Лид: {$company->name}",
                'currency' => $opts['currency'] ?? config('crm.currencies.default', 'RUB'),
                'owner_user_id' => $ownerId,
                'department_id' => $owner?->department_id,
                'tags' => $opts['tags'] ?? [],
                'extra_fields' => $opts['extra_fields'] ?? [],
                'stage_changed_at' => now(),
            ]);

            // Creation event in stage history (from_stage_id = null → creation).
            // user_id is null: an inbound lead has no authenticated actor.
            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $stageId,
                'user_id' => null,
                'created_at' => $deal->created_at,
            ]);

            DealCreated::dispatch($deal);

            return $deal;
        });
    }

    /**
     * Partial update. stage_id is rejected here (defence in depth alongside the
     * FormRequest) — the only path to change stage is DealMoveService::move().
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data): Deal
    {
        unset($data['stage_id']);

        $deal->update($data);
        $deal->refresh();

        return $deal;
    }

    public function delete(Deal $deal): void
    {
        DB::transaction(function () use ($deal): void {
            // deal_products / deal_contacts / deal_stage_history cascade via FK.
            $deal->delete();
        });
    }

    /**
     * Recompute Deal.amount as the sum of its line items (kopecks).
     */
    public function recalcAmount(Deal $deal): Deal
    {
        $sum = (int) DealProduct::query()
            ->where('deal_id', $deal->id)
            ->sum('amount');

        $deal->update(['amount' => $sum]);
        $deal->refresh();

        return $deal;
    }

    /**
     * Apply the row-level visibility scope to a base Deal query.
     *
     * @return Builder<Deal>
     */
    private function scopedQuery(VisibilityScope $scope, User $user): Builder
    {
        $query = Deal::query();

        return match ($scope) {
            VisibilityScope::All => $query,
            VisibilityScope::Department => $query->whereIn('department_id', $this->visibility->departmentSubtreeIds($user)),
            VisibilityScope::Own => $query->where('owner_user_id', $user->id),
        };
    }
}
