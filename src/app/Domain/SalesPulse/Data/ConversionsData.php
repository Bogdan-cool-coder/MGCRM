<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * ConversionsData — the /conversions analysis result (spec §6.2). Built from PLAN
 * snapshots over the period; rendered by ConversionsRenderer.
 *
 *   - gates: per consecutive funnel position pair (1,2)..(N-1,N):
 *       { from, to, touched, passed, pct }   touched = deals ever at `from`,
 *       passed = of those whose max_position > from. (spec §6.2)
 *   - funnel: { in_funnel, success, overall_pct } — сквозная воронка.
 *   - losses: { lost_by_stage: code=>n, cold_by_stage: code=>n } — deals whose LAST
 *       point is lost(-2)/cold(-1), rolled back to the last real stage.
 *   - velocity: per real stage avg days (unique dates on (lead,stage)); slow when
 *       avg >= 3 → " ← залипают". position N (success) excluded.
 *   - bottleneckGate: gate with the minimum pct → " ← узкое место".
 *
 * Immutable VO. The renderer owns all glyph/label formatting; this DTO holds the
 * numbers + the stage labels (code/name/emoji) it needs.
 *
 * @phpstan-type GateRow array{from: int, to: int, from_label: string, to_label: string, touched: int, passed: int, pct: int}
 * @phpstan-type VelocityRow array{position: int, label: string, avg_days: float, slow: bool}
 * @phpstan-type LossRow array{code: string, label: string, count: int}
 */
final readonly class ConversionsData
{
    /**
     * @param  list<GateRow>  $gates
     * @param  array{in_funnel: int, success: int, overall_pct: int}  $funnel
     * @param  list<LossRow>  $lostByStage
     * @param  list<LossRow>  $coldByStage
     * @param  list<VelocityRow>  $velocity
     */
    public function __construct(
        public string $periodLabel,
        public array $gates,
        public array $funnel,
        public array $lostByStage,
        public array $coldByStage,
        public array $velocity,
        public ?int $bottleneckGateIndex,
    ) {}
}
