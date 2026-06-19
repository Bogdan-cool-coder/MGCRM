<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Support\Collection;

/**
 * StageClassificationService — funnel-movement semantics ported 1-for-1 from the
 * AMO oversight bot's pipelines.py (spec §1.3), re-expressed against MGCRM stages.
 *
 * The bot hard-coded AMO status ids; we derive everything from PipelineStage flags
 * + sort_order, so the same rules drive ANY funnel (the locked "Продажи" pipeline
 * today, AI Global tomorrow) without hard-coded ids:
 *
 *   funnel_position (normalised, spec §1.3):
 *     lost                 → -2
 *     cold                 → -1
 *     null / unknown stage →  0
 *     won (success bucket) →  top  (rank above every real stage)
 *     real stage           →  rank 1..N by sort_order among non-won/lost/cold
 *
 * "Real" = a stage that is neither won nor lost nor cold. The rank is computed
 * against the stage's OWN pipeline (so two funnels never bleed into each other);
 * the pipeline's real stages are loaded once and memoised per pipeline_id.
 *
 * Cold detection (documented assumption): primary by code via
 * config('salespulse.cold_stage_codes'); fallback heuristic for stages whose code
 * is not listed — hidden_by_default && !is_won && !is_lost. The seeded `cold`
 * stage matches the code; `lost` never matches (is_lost is true).
 *
 * Pure logic — no transactions, no writes. The only DB touch is reading sibling
 * stages to compute a rank; that read is memoised.
 */
class StageClassificationService
{
    /**
     * Memoised map of pipeline_id => [stage_id => rank(1..N)] for REAL stages.
     *
     * @var array<int, array<int, int>>
     */
    private array $realRankCache = [];

    /**
     * Memoised count of real stages per pipeline_id (used as the won "top" rank).
     *
     * @var array<int, int>
     */
    private array $realCountCache = [];

    public function isWon(?PipelineStage $stage): bool
    {
        return $stage !== null && $stage->is_won === true;
    }

    public function isLost(?PipelineStage $stage): bool
    {
        return $stage !== null && $stage->is_lost === true;
    }

    /**
     * A cold (freeze) stage. Primary signal: code in config('salespulse.cold_stage_codes').
     * Fallback for stages with an unlisted code: hidden && !won && !lost.
     */
    public function isCold(?PipelineStage $stage): bool
    {
        if ($stage === null || $stage->is_won === true || $stage->is_lost === true) {
            return false;
        }

        /** @var list<string> $coldCodes */
        $coldCodes = config('salespulse.cold_stage_codes', []);

        if ($stage->code !== null && in_array($stage->code, $coldCodes, true)) {
            return true;
        }

        // Fallback heuristic — only meaningful when the code is not explicitly
        // mapped. A hidden, non-terminal stage behaves like a freeze bucket.
        return $stage->hidden_by_default === true;
    }

    /**
     * Normalised funnel position (spec §1.3). See class docblock for the buckets.
     */
    public function funnelPosition(?PipelineStage $stage): int
    {
        if ($stage === null) {
            return 0;
        }

        if ($this->isLost($stage)) {
            return -2;
        }

        if ($this->isCold($stage)) {
            return -1;
        }

        if ($this->isWon($stage)) {
            // Success bucket sits above every real stage (top of the funnel).
            return $this->realStageCount($stage->pipeline_id) + 1;
        }

        // Real stage — rank by sort_order among its pipeline's real stages.
        $ranks = $this->realRankMap($stage->pipeline_id);

        return $ranks[$stage->id] ?? 0;
    }

    /**
     * Forward move (spec §1.3): false if the target is cold/lost; otherwise the
     * target position must be strictly higher than the source.
     */
    public function isForwardMove(?PipelineStage $from, ?PipelineStage $to): bool
    {
        if ($this->isCold($to) || $this->isLost($to)) {
            return false;
        }

        return $this->funnelPosition($to) > $this->funnelPosition($from);
    }

    /**
     * Funnel downgrade (spec §1.3): false if positions are equal or the target is
     * lost; if BOTH positions are 0 (unknown) → false; otherwise target below source.
     * Because cold = -1, a real → cold move is a downgrade.
     */
    public function isFunnelDowngrade(?PipelineStage $from, ?PipelineStage $to): bool
    {
        if ($this->isLost($to)) {
            return false;
        }

        $posFrom = $this->funnelPosition($from);
        $posTo = $this->funnelPosition($to);

        if ($posFrom === $posTo) {
            return false;
        }

        if ($posFrom === 0 && $posTo === 0) {
            return false;
        }

        return $posTo < $posFrom;
    }

    /**
     * Stage jump (spec §1.3): a move that skips at least one stage, measured by the
     * raw sort_order delta (Δsort_order ≥ 2). Null stages → not a jump.
     */
    public function isStageJump(?PipelineStage $from, ?PipelineStage $to): bool
    {
        if ($from === null || $to === null) {
            return false;
        }

        return abs($to->sort_order - $from->sort_order) >= 2;
    }

    /**
     * Sort key that orders stages hottest → coldest for report rendering
     * (spec §1.3): success=1, hot=2, warm/trial=3, walking/meeting=4, schedule=5,
     * qualif=6, inbound/outbound/unsorted=7, cold=8, lost=9.
     *
     * Derived from flags + position rather than ids: won → 1, lost → 9, cold → 8;
     * real stages map "higher position = hotter" onto the 2..7 band so the order
     * holds for any funnel depth, with the seeded codes pinned for an exact port.
     */
    public function statusSortKey(?PipelineStage $stage): int
    {
        if ($stage === null) {
            return 7;
        }

        if ($this->isWon($stage)) {
            return 1;
        }

        if ($this->isLost($stage)) {
            return 9;
        }

        if ($this->isCold($stage)) {
            return 8;
        }

        // Exact port of the AMO key for the locked sales codes.
        $byCode = [
            'hot' => 2,
            'warm' => 3,
            'meeting' => 4,
            'schedule_meeting' => 5,
            'qualify' => 6,
            'new' => 7,
        ];

        if ($stage->code !== null && isset($byCode[$stage->code])) {
            return $byCode[$stage->code];
        }

        // Generic fallback for an unmapped real stage: map its rank into the 2..7
        // hot→start band so deeper-in-funnel stages sort hotter.
        $count = max(1, $this->realStageCount($stage->pipeline_id));
        $rank = $this->funnelPosition($stage); // 1..count for real stages

        if ($rank < 1) {
            return 7;
        }

        // rank == count (deepest real) → 2 (hottest), rank == 1 → ~7 (start).
        $key = 2 + (int) round((($count - $rank) / max(1, $count - 1)) * 5);

        return min(7, max(2, $key));
    }

    /**
     * Real (non-won/lost/cold) stages of a pipeline keyed by id → rank 1..N by
     * sort_order. Memoised per pipeline_id.
     *
     * @return array<int, int>
     */
    private function realRankMap(int $pipelineId): array
    {
        if (isset($this->realRankCache[$pipelineId])) {
            return $this->realRankCache[$pipelineId];
        }

        $this->loadRealStages($pipelineId);

        return $this->realRankCache[$pipelineId];
    }

    private function realStageCount(int $pipelineId): int
    {
        if (isset($this->realCountCache[$pipelineId])) {
            return $this->realCountCache[$pipelineId];
        }

        $this->loadRealStages($pipelineId);

        return $this->realCountCache[$pipelineId];
    }

    private function loadRealStages(int $pipelineId): void
    {
        /** @var Collection<int, PipelineStage> $stages */
        $stages = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'pipeline_id', 'code', 'sort_order', 'is_won', 'is_lost', 'hidden_by_default']);

        $real = $stages->filter(
            fn (PipelineStage $s): bool => ! $this->isWon($s) && ! $this->isLost($s) && ! $this->isCold($s),
        )->values();

        $rankMap = [];
        foreach ($real as $index => $stage) {
            $rankMap[$stage->id] = $index + 1;
        }

        $this->realRankCache[$pipelineId] = $rankMap;
        $this->realCountCache[$pipelineId] = $real->count();
    }
}
