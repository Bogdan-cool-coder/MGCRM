<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Data\StageConversionFilters;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * StageConversionService — GET /api/reports/stage-conversions (contract §6.8
 * `stage` block / plan's improvement #3): the HONEST stage-to-stage
 * conversion, built from `deal_stage_history`'s actual transition log —
 * something SpaceCRM cannot do (it only has task-ratio conversions, contract
 * §6.8 "our advantage"/"наш козырь"). No plan input — fact-only.
 *
 * ---- Semantics (contract-required decision, documented here) ----
 *
 * "Entered stage X" = the FIRST `deal_stage_history` row for that deal with
 * `to_stage_id = X` (by `created_at` ASC). Every deal writes a creation row
 * (`from_stage_id=null, to_stage_id=<entry stage>` — DealService::create,
 * verified against the real code) in addition to move-rows
 * (DealMoveService::move), so "entered" uniformly covers both the deal's
 * original entry point and every later stage it was moved into.
 *
 * A conversion pair is two ADJACENT stages in the pipeline's funnel order
 * (Pipeline::stages(), which already orders won/lost to the tail) —
 * `stage[i] → stage[i+1]`. For each pair:
 *   - `den_count` = COUNT of deals whose first entry into stage[i] happened
 *     (ever, or within the filtered period if year/month given).
 *   - `num_count` = COUNT of that SAME set of deals whose first entry into
 *     stage[i+1] is AFTER (or the same day as, since move + advance can be
 *     the same beat) their first entry into stage[i].
 *   - `fact_pct` = num/den × 100.
 *
 * FIRST ENTRY ONLY — a deal that later moves BACKWARD into stage[i] (a
 * "return") does not create a second entry event and does not inflate
 * den_count; a deal that skips stage[i] entirely (allow_stage_skip) never
 * enters it and is correctly excluded from that pair's denominator (it still
 * counts toward whichever pair it DID transition through). This keeps a
 * branch/return from double-counting or double-crediting a conversion —
 * each deal contributes at most once to each pair's numerator and
 * denominator, matching the plan's explicit ask ("ветвления/возвраты не
 * ломают счёт").
 *
 * Read-scope: visibility-scoped like the other Ф4 reports — Own/Department/
 * All restrict which deals' history rows are counted (via deals.owner_user_id
 * / department_id), mirroring VisibilityResolver::applyScope.
 */
class StageConversionService
{
    public function __construct(
        private readonly VisibilityResolver $visibility,
    ) {}

    /**
     * @return array{meta: array<string, mixed>, chain: list<array<string, mixed>>}
     */
    public function build(StageConversionFilters $filters, User $viewer): array
    {
        $pipeline = Pipeline::query()->findOrFail($filters->pipelineId);
        $stages = $pipeline->stages()->get(); // already ordered: active by sort_order, won/lost tail

        $firstEntryByStage = $this->firstEntryPerDealPerStage($filters, $viewer);

        $chain = [];
        $n = $stages->count();

        for ($i = 0; $i < $n - 1; $i++) {
            $fromStage = $stages[$i];
            $toStage = $stages[$i + 1];

            $fromEntries = $firstEntryByStage->get($fromStage->id, collect());
            $toEntries = $firstEntryByStage->get($toStage->id, collect());

            $denCount = $fromEntries->count();
            $numCount = 0;

            foreach ($fromEntries as $dealId => $fromEnteredAt) {
                $toEnteredAt = $toEntries->get($dealId);

                if ($toEnteredAt !== null && $toEnteredAt >= $fromEnteredAt) {
                    $numCount++;
                }
            }

            $factPct = $denCount > 0 ? (int) round($numCount / $denCount * 100) : null;

            $chain[] = [
                'name' => $fromStage->name.' → '.$toStage->name,
                'from_stage_id' => $fromStage->id,
                'to_stage_id' => $toStage->id,
                'den_count' => $denCount,
                'num_count' => $numCount,
                'fact_pct' => $factPct,
            ];
        }

        return [
            'meta' => [
                'pipeline_id' => $pipeline->id,
                'year' => $filters->year,
                'month' => $filters->month,
            ],
            'chain' => $chain,
        ];
    }

    /**
     * Per stage_id, per deal_id → the deal's FIRST entry timestamp into that
     * stage (MIN created_at across `deal_stage_history` rows with that
     * to_stage_id), restricted to deals visible to $viewer and (optionally)
     * to transitions whose created_at falls in the filtered period. One
     * query, grouped in PHP — history tables are append-only and small enough
     * per pipeline that a single fetch + PHP MIN is simpler and more portable
     * (sqlite/pgsql) than a driver-specific window function.
     *
     * @return Collection<int, Collection<int, string>>
     *                                                  stage_id => (deal_id => first entered_at ISO string)
     */
    private function firstEntryPerDealPerStage(StageConversionFilters $filters, User $viewer): Collection
    {
        $query = DB::table('deal_stage_history as dsh')
            ->join('deals', 'dsh.deal_id', '=', 'deals.id')
            ->where('deals.pipeline_id', $filters->pipelineId)
            ->whereNull('deals.archived_at')
            ->whereNotNull('dsh.to_stage_id');

        if ($filters->year !== null) {
            $isPg = DB::connection()->getDriverName() === 'pgsql';
            $yearExpr = $isPg ? 'EXTRACT(YEAR FROM dsh.created_at)' : "strftime('%Y', dsh.created_at)";
            $query->whereRaw($yearExpr.' = ?', [(string) $filters->year]);

            if ($filters->month !== null) {
                $monthExpr = $isPg ? 'EXTRACT(MONTH FROM dsh.created_at)' : "strftime('%m', dsh.created_at)";
                // sqlite's strftime('%m', ...) zero-pads ("03"); pgsql's
                // EXTRACT(MONTH..) is unpadded numeric — bind the
                // driver-appropriate literal (same fix as
                // ConversionReportService::countPairSide).
                $monthParam = $isPg ? (string) $filters->month : str_pad((string) $filters->month, 2, '0', STR_PAD_LEFT);
                $query->whereRaw($monthExpr.' = ?', [$monthParam]);
            }
        }

        $scope = $this->visibility->resolve($viewer);

        match ($scope) {
            VisibilityScope::All => null,
            VisibilityScope::Department => $query->where(
                fn ($q) => $q
                    ->where('deals.owner_user_id', $viewer->id)
                    ->orWhereIn('deals.department_id', $this->visibility->departmentSubtreeIds($viewer)),
            ),
            VisibilityScope::Own => $query->where('deals.owner_user_id', $viewer->id),
        };

        $rows = $query
            ->orderBy('dsh.created_at')
            ->get(['dsh.deal_id', 'dsh.to_stage_id', 'dsh.created_at']);

        $result = collect();

        foreach ($rows as $row) {
            $stageId = (int) $row->to_stage_id;
            $dealId = (int) $row->deal_id;

            if (! $result->has($stageId)) {
                $result->put($stageId, collect());
            }

            // Rows are ordered by created_at ASC, so the FIRST time we see a
            // (stageId, dealId) pair IS the first entry — never overwrite.
            if (! $result->get($stageId)->has($dealId)) {
                $result->get($stageId)->put($dealId, (string) $row->created_at);
            }
        }

        return $result;
    }
}
