<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\ConversionsData;
use App\Domain\SalesPulse\Data\StageMeta;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * ConversionsService — the /conversions funnel analysis (spec §6.2). It reads the
 * team's PLAN snapshots over a period and reconstructs each deal's trajectory of
 * funnel POSITIONS (per StageClassificationService): every snapshot point is a
 * (deal, date, position) sample.
 *
 *   GATES (1,2)..(N-1,N): per gate (from→to):
 *     touched = deals that ever had a point at position `from`,
 *     passed  = of those, the ones whose max_position > from,
 *     pct     = round(passed*100/touched), 0 when touched=0.
 *
 *   FUNNEL: in_funnel = deals touched at all; success = deals with a point at the
 *     top real position (won bucket); overall_pct = round(success*100/in_funnel).
 *
 *   LOSSES: deals whose LAST point is lost(-2)/cold(-1) → roll back to their last
 *     REAL-stage point and increment lost_by_stage / cold_by_stage by that code.
 *
 *   VELOCITY: per real stage, the avg of "unique dates a deal held that stage"
 *     over all deals that held it; the top real position is excluded; avg >= 3 is
 *     "slow" (← залипают).
 *
 *   BOTTLENECK: the gate with the minimum pct (← узкое место).
 *
 * Period parsing (spec §6.2): no arg → last 30 days; N → last N days; ISO date →
 * from that date to today; two dates → an explicit range.
 */
class ConversionsService
{
    public function __construct(
        private readonly StageClassificationService $classifier,
    ) {}

    /**
     * @var array<int, PipelineStage|null> stage_id => stage, memoised per call.
     */
    private array $stageCache = [];

    /**
     * Build the conversions analysis for a team over a period.
     *
     * @param  list<int>  $managerIds  The team's managers (snapshot owners).
     * @param  list<int>  $pipelineIds  The team's funnels (for the position rank base).
     */
    public function analyze(array $managerIds, array $pipelineIds, CarbonImmutable $from, CarbonImmutable $to): ConversionsData
    {
        $this->stageCache = [];

        // Per deal: ordered (date => position, code, stageId) points.
        $trajectories = $this->loadTrajectories($managerIds, $from, $to);

        // The funnel depth = number of real stages across the team's pipelines.
        $maxPosition = $this->maxRealPosition($pipelineIds);

        $gates = $this->buildGates($trajectories, $maxPosition);
        $funnel = $this->buildFunnel($trajectories, $maxPosition);
        [$lostByStage, $coldByStage] = $this->buildLosses($trajectories);
        $velocity = $this->buildVelocity($trajectories, $maxPosition);
        $bottleneck = $this->bottleneckGate($gates);

        return new ConversionsData(
            periodLabel: $from->format('d.m.Y').' — '.$to->format('d.m.Y'),
            gates: $gates,
            funnel: $funnel,
            lostByStage: $lostByStage,
            coldByStage: $coldByStage,
            velocity: $velocity,
            bottleneckGateIndex: $bottleneck,
        );
    }

    /**
     * Parse the /conversions period argument tokens (spec §6.2).
     *
     * @param  list<string>  $args
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function parsePeriod(array $args, ?CarbonImmutable $now = null): array
    {
        $tz = (string) config('salespulse.timezone', 'Asia/Dubai');
        $now ??= CarbonImmutable::now($tz);
        $today = $now->setTimezone($tz)->endOfDay();

        $args = array_values(array_filter($args, static fn (string $a): bool => trim($a) !== ''));

        // No arg → last 30 days.
        if ($args === []) {
            return [$today->subDays(30)->startOfDay(), $today];
        }

        // Single numeric → last N days.
        if (count($args) === 1 && ctype_digit($args[0])) {
            $n = (int) $args[0];

            return [$today->subDays($n)->startOfDay(), $today];
        }

        // Single ISO date → from that date to today.
        if (count($args) === 1) {
            $start = $this->parseDate($args[0], $tz);
            if ($start !== null) {
                return [$start->startOfDay(), $today];
            }

            return [$today->subDays(30)->startOfDay(), $today];
        }

        // Two dates → explicit range.
        $start = $this->parseDate($args[0], $tz);
        $end = $this->parseDate($args[1], $tz);

        if ($start !== null && $end !== null) {
            return [$start->startOfDay(), $end->endOfDay()];
        }

        return [$today->subDays(30)->startOfDay(), $today];
    }

    private function parseDate(string $raw, string $tz): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'd.m.Y', 'd.m.y', 'd.m'] as $fmt) {
            try {
                $dt = CarbonImmutable::createFromFormat($fmt, trim($raw), $tz);
                if ($dt !== false) {
                    return $dt;
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        return null;
    }

    /**
     * Load every team PLAN snapshot in the window and reduce to per-deal ordered
     * points: deal_id => [ on_date => ['position'=>int,'stage_id'=>?int,'code'=>?string] ].
     *
     * @param  list<int>  $managerIds
     * @return array<int, array<string, array{position: int, stage_id: ?int, code: ?string}>>
     */
    private function loadTrajectories(array $managerIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($managerIds === []) {
            return [];
        }

        // on_date is date-cast (stored "Y-m-d 00:00:00"), so a string-bounded
        // whereBetween drops the upper-bound day ("...11 00:00:00" > "...11"). Use
        // whereDate bounds so the range is inclusive on both ends.
        /** @var Collection<int, PulseSnapshot> $rows */
        $rows = PulseSnapshot::query()
            ->whereIn('manager_id', $managerIds)
            ->where('kind', SnapKind::Plan->value)
            ->whereDate('on_date', '>=', $from->toDateString())
            ->whereDate('on_date', '<=', $to->toDateString())
            ->orderBy('on_date')
            ->get(['id', 'on_date', 'data']);

        // Collect referenced stage ids first to prime the cache in one query.
        $stageIds = [];
        $perRow = [];
        foreach ($rows as $snap) {
            /** @var array<string, mixed> $data */
            $data = $snap->data ?? [];
            /** @var array<string, array<string, mixed>> $leads */
            $leads = $data['leads_by_id'] ?? [];
            $onDate = $snap->on_date instanceof CarbonImmutable
                ? $snap->on_date->toDateString()
                : CarbonImmutable::parse((string) $snap->on_date)->toDateString();

            $perRow[] = [$onDate, $leads];
            foreach ($leads as $lead) {
                $sid = $lead['status_id'] ?? null;
                if ($sid !== null) {
                    $stageIds[(int) $sid] = true;
                }
            }
        }

        $this->primeStageCache(array_keys($stageIds));

        $trajectories = [];
        foreach ($perRow as [$onDate, $leads]) {
            foreach ($leads as $dealId => $lead) {
                $dealId = (int) $dealId;
                $sid = isset($lead['status_id']) ? (int) $lead['status_id'] : null;
                $stage = $this->stage($sid);
                $position = $this->classifier->funnelPosition($stage);

                // Keep the LAST point per (deal, date) — snapshots are one per day,
                // but guard anyway.
                $trajectories[$dealId][$onDate] = [
                    'position' => $position,
                    'stage_id' => $sid,
                    'code' => $stage?->code,
                ];
            }
        }

        // Order each deal's points by date.
        foreach ($trajectories as &$points) {
            ksort($points);
        }
        unset($points);

        return $trajectories;
    }

    /**
     * @param  array<int, array<string, array{position: int, stage_id: ?int, code: ?string}>>  $trajectories
     * @return list<array<string, mixed>>
     */
    private function buildGates(array $trajectories, int $maxPosition): array
    {
        $gates = [];

        for ($from = 1; $from < $maxPosition; $from++) {
            $to = $from + 1;
            $touched = 0;
            $passed = 0;

            foreach ($trajectories as $points) {
                $positions = array_map(static fn (array $p): int => $p['position'], $points);
                $real = array_filter($positions, static fn (int $p): bool => $p >= 1);

                $hadFrom = in_array($from, $real, true);
                if (! $hadFrom) {
                    continue;
                }

                $touched++;
                $maxPos = $real === [] ? 0 : max($real);
                if ($maxPos > $from) {
                    $passed++;
                }
            }

            $gates[] = [
                'from' => $from,
                'to' => $to,
                'from_label' => $this->positionLabel($from),
                'to_label' => $this->positionLabel($to),
                'touched' => $touched,
                'passed' => $passed,
                'pct' => $this->pct($passed, $touched),
            ];
        }

        return $gates;
    }

    /**
     * @param  array<int, array<string, array{position: int, stage_id: ?int, code: ?string}>>  $trajectories
     * @return array{in_funnel: int, success: int, overall_pct: int}
     */
    private function buildFunnel(array $trajectories, int $maxPosition): array
    {
        $inFunnel = 0;
        $success = 0;
        $topPosition = $maxPosition + 1; // won bucket sits above the deepest real stage.

        foreach ($trajectories as $points) {
            $positions = array_map(static fn (array $p): int => $p['position'], $points);
            $real = array_filter($positions, static fn (int $p): bool => $p >= 1);

            if ($real === []) {
                // A deal that only ever sat in cold/lost/unknown is not "in funnel".
                if (in_array($topPosition, $positions, true)) {
                    // (shouldn't happen — won is >=1) guard anyway.
                    $success++;
                }

                continue;
            }

            $inFunnel++;
            if (in_array($topPosition, $positions, true)) {
                $success++;
            }
        }

        return [
            'in_funnel' => $inFunnel,
            'success' => $success,
            'overall_pct' => $this->pct($success, $inFunnel),
        ];
    }

    /**
     * @param  array<int, array<string, array{position: int, stage_id: ?int, code: ?string}>>  $trajectories
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildLosses(array $trajectories): array
    {
        $lostBy = [];
        $coldBy = [];

        foreach ($trajectories as $points) {
            $ordered = array_values($points);
            if ($ordered === []) {
                continue;
            }

            $last = end($ordered);
            $lastPos = $last['position'];

            $isLost = $lastPos === -2;
            $isCold = $lastPos === -1;
            if (! $isLost && ! $isCold) {
                continue;
            }

            // Roll back to the last REAL-stage point.
            $rollback = $this->lastRealPoint($ordered);
            $code = $rollback['code'] ?? 'unknown';
            $label = $this->codeLabel($rollback);

            if ($isLost) {
                $lostBy[$code] ??= ['code' => (string) $code, 'label' => $label, 'count' => 0];
                $lostBy[$code]['count']++;
            } else {
                $coldBy[$code] ??= ['code' => (string) $code, 'label' => $label, 'count' => 0];
                $coldBy[$code]['count']++;
            }
        }

        return [
            array_values($lostBy),
            array_values($coldBy),
        ];
    }

    /**
     * @param  array<int, array<string, array{position: int, stage_id: ?int, code: ?string}>>  $trajectories
     * @return list<array<string, mixed>>
     */
    private function buildVelocity(array $trajectories, int $maxPosition): array
    {
        // position => [ total_unique_dates, deal_count ].
        $byPosition = [];

        foreach ($trajectories as $points) {
            // Count distinct dates per (deal, position) for real positions.
            $datesByPosition = [];
            foreach ($points as $onDate => $point) {
                $pos = $point['position'];
                if ($pos < 1 || $pos >= $maxPosition + 1) {
                    continue; // skip cold/lost/unknown and the won-top position.
                }
                $datesByPosition[$pos][$onDate] = true;
            }

            foreach ($datesByPosition as $pos => $dates) {
                $byPosition[$pos] ??= ['sum' => 0, 'deals' => 0];
                $byPosition[$pos]['sum'] += count($dates);
                $byPosition[$pos]['deals']++;
            }
        }

        $velocity = [];
        for ($pos = 1; $pos <= $maxPosition; $pos++) {
            if (! isset($byPosition[$pos]) || $byPosition[$pos]['deals'] === 0) {
                continue;
            }
            $avg = $byPosition[$pos]['sum'] / $byPosition[$pos]['deals'];
            $velocity[] = [
                'position' => $pos,
                'label' => $this->positionLabel($pos),
                'avg_days' => round($avg, 1),
                'slow' => $avg >= 3,
            ];
        }

        return $velocity;
    }

    /**
     * The gate index (0-based) with the minimum pct among touched gates → узкое
     * место. Null when no gate had any traffic.
     *
     * @param  list<array<string, mixed>>  $gates
     */
    private function bottleneckGate(array $gates): ?int
    {
        $bestIndex = null;
        $bestPct = null;

        foreach ($gates as $i => $gate) {
            if ((int) $gate['touched'] === 0) {
                continue;
            }
            $pct = (int) $gate['pct'];
            if ($bestPct === null || $pct < $bestPct) {
                $bestPct = $pct;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    /**
     * The last real-stage (position >= 1) point in a deal's ordered trajectory.
     *
     * @param  list<array{position: int, stage_id: ?int, code: ?string}>  $ordered
     * @return array{position: int, stage_id: ?int, code: ?string}
     */
    private function lastRealPoint(array $ordered): array
    {
        for ($i = count($ordered) - 1; $i >= 0; $i--) {
            if ($ordered[$i]['position'] >= 1) {
                return $ordered[$i];
            }
        }

        return ['position' => 0, 'stage_id' => null, 'code' => null];
    }

    /**
     * Label for a funnel position: resolve any cached stage with that rank, else a
     * bare "поз. {n}".
     */
    private function positionLabel(int $position): string
    {
        foreach ($this->stageCache as $stage) {
            if ($stage !== null && $this->classifier->funnelPosition($stage) === $position) {
                return StageMeta::forStage($stage)->label($stage->name);
            }
        }

        return "поз. {$position}";
    }

    /**
     * @param  array{position: int, stage_id: ?int, code: ?string}  $point
     */
    private function codeLabel(array $point): string
    {
        $stage = $this->stage($point['stage_id'] ?? null);
        $meta = StageMeta::forStage($stage);
        $name = $stage?->name ?? ($point['code'] ?? 'неизвестно');

        return $meta->label((string) $name);
    }

    private function maxRealPosition(array $pipelineIds): int
    {
        $max = 0;
        foreach ($pipelineIds as $pid) {
            /** @var Collection<int, PipelineStage> $stages */
            $stages = PipelineStage::query()->where('pipeline_id', $pid)->get();
            foreach ($stages as $stage) {
                $pos = $this->classifier->funnelPosition($stage);
                // Real positions are 1..N; the won bucket is N+1 — ignore it here.
                if (! $this->classifier->isWon($stage) && $pos > $max) {
                    $max = $pos;
                }
            }
        }

        return max($max, 1);
    }

    /**
     * @param  list<int>  $stageIds
     */
    private function primeStageCache(array $stageIds): void
    {
        $missing = array_values(array_filter(
            $stageIds,
            fn (int $id): bool => ! array_key_exists($id, $this->stageCache),
        ));

        if ($missing === []) {
            return;
        }

        /** @var Collection<int, PipelineStage> $stages */
        $stages = PipelineStage::query()->whereIn('id', $missing)->get();
        $byId = $stages->keyBy('id');

        foreach ($missing as $id) {
            $this->stageCache[$id] = $byId->get($id);
        }
    }

    private function stage(?int $stageId): ?PipelineStage
    {
        if ($stageId === null) {
            return null;
        }

        return $this->stageCache[$stageId] ?? null;
    }

    private function pct(int $x, int $y): int
    {
        if ($y === 0) {
            return 0;
        }

        return (int) round($x * 100 / $y);
    }
}
