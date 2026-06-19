<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Data\StageMeta;
use App\Domain\SalesPulse\Data\WeeklyAgg;
use App\Domain\SalesPulse\Data\WeeklyData;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * WeeklyAggregationService — the AMO bot's weekly data collection (port of
 * services/weekly.py, spec §5.2). It walks one working week (Mon-Fri) of a team's
 * PLAN + FACT snapshots and produces a WeeklyData: per-manager metrics, team
 * current/prev aggregates, top_movements and top_stuck.
 *
 * Per working day, per manager, the six daily metrics are recomputed via
 * MetricsService (morning PLAN vs evening FACT) and summed into the manager's
 * weekly row and the team agg. Deal trajectories are tracked across the week from
 * the snapshots' leads_by_id: the FIRST observed stage is `from`, the LAST is
 * `to`; a deal can contribute to several counters (a forward mover that later
 * sticks, etc. — spec §5.2 "сделка может попасть в несколько счётчиков").
 *
 * top_movements (spec §5.2): delta = funnel_position(to) − funnel_position(from),
 * keep delta>0, sort (-delta, company), top-5, jump = delta>=2, raw_task_result =
 * the deal's latest FACT task result. top_stuck: latest stage, exclude
 * success/lost, days_in_stage > weekly SLA threshold, sort (-days, company),
 * top-5, raw_plan_text = the deal's latest plan task text.
 */
class WeeklyAggregationService
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly NotesService $notes,
        private readonly StageClassificationService $classifier,
    ) {}

    /**
     * @var array<int, PipelineStage|null> stage_id => stage (null = absent), memoised per call.
     */
    private array $stageCache = [];

    /**
     * Aggregate one working week for a team.
     *
     * @param  list<User>  $managers  The team's managers.
     * @param  CarbonImmutable  $weekStart  Monday of the target week.
     * @param  list<int>  $pipelineIds  The team's funnels (for metric recompute).
     * @param  string|null  $teamChatId  Team chat for the skip lookup.
     */
    public function aggregate(
        string $teamName,
        array $managers,
        CarbonImmutable $weekStart,
        array $pipelineIds,
        ?string $teamChatId = null,
    ): WeeklyData {
        $this->stageCache = [];

        $monday = $weekStart->startOfDay();
        $workingDays = $this->workingDays($monday); // Mon..Fri

        $this->primeStageCacheForWeek($managers, $monday, $monday->addDays(7));

        $managerRows = [];
        $teamDone = 0;
        $teamPlan = 0;
        $teamStatusUpdates = 0;
        $teamSuccess = 0;
        $teamLost = 0;
        $teamDowngrades = 0;
        $teamExtra = 0;
        $teamLeadIds = [];
        $daysWithDataTotal = 0;

        // Trajectory tracking across the whole week (deal_id keyed).
        /** @var array<int, array{manager: string, company: string, first_stage: ?int, last_stage: ?int, last_days_in_stage: int, raw_task_result: string, raw_plan_text: string}> $trajectories */
        $trajectories = [];

        foreach ($managers as $manager) {
            $row = [
                'name' => (string) $manager->full_name,
                'days_with_plan' => 0,
                'days_skipped' => 0,
                'activity_pct' => 0,
                'done' => 0,
                'plan' => 0,
                'status_updates' => 0,
                'leads' => 0,
                'success' => 0,
                'lost' => 0,
                'status_downgrades' => 0,
            ];

            $managerLeadIds = [];

            foreach ($workingDays as $day) {
                if ($this->isSkipped($manager, $day, $teamChatId)) {
                    $row['days_skipped']++;

                    continue;
                }

                $plan = $this->loadSnapshot($manager, $day, SnapKind::Plan);
                if ($plan === null) {
                    continue; // No plan that day → contributes nothing.
                }

                $row['days_with_plan']++;
                $daysWithDataTotal++;

                $fact = $this->loadSnapshot($manager, $day, SnapKind::Fact);
                $evening = $fact ?? $plan; // No fact → fall back to the plan snapshot.

                $dealsWithNotes = $this->notes->dealIdsWithNoteToday($manager, $day);
                $metrics = $this->metrics->compute($plan, $evening, $dealsWithNotes);

                $row['done'] += $metrics->activityDone;
                $row['plan'] += $metrics->activityTotal;
                $row['status_updates'] += $metrics->statusUpdates;
                $row['lost'] += $metrics->losts;
                $row['status_downgrades'] += $metrics->statusDowngrades;

                // Team-level numerators.
                $teamDone += $metrics->activityDone;
                $teamPlan += $metrics->activityTotal;
                $teamStatusUpdates += $metrics->statusUpdates;
                $teamLost += $metrics->losts;
                $teamDowngrades += $metrics->statusDowngrades;
                $teamExtra += $metrics->extraTasks;

                // Trajectory + unique-lead accumulation from the day's plan + evening.
                $this->accumulate($manager, $plan, $evening, $managerLeadIds, $teamLeadIds, $trajectories);
            }

            $row['leads'] = count($managerLeadIds);
            $row['activity_pct'] = $this->pct($row['done'], $row['plan']);

            // Success per manager = deals whose final stage is won (spec §5.2 counts
            // success at the team + manager level from trajectories).
            $row['success'] = $this->successCountForManager($trajectories, (string) $manager->full_name);

            $managerRows[] = $row;
        }

        $teamSuccess = $this->successCountTotal($trajectories);

        $current = new WeeklyAgg(
            done: $teamDone,
            plan: $teamPlan,
            statusUpdates: $teamStatusUpdates,
            uniqueLeads: count($teamLeadIds),
            success: $teamSuccess,
            lost: $teamLost,
            statusDowngrades: $teamDowngrades,
            extraTasks: $teamExtra,
        );

        $prev = $this->aggregatePrevAgg($teamName, $managers, $monday->subDays(7), $pipelineIds, $teamChatId);

        $topMovements = $this->buildMovements($trajectories);
        $topStuck = $this->buildStuck($trajectories);

        $isPartial = $this->isPartialWeek($monday);

        return new WeeklyData(
            team: $teamName,
            week: $monday->toDateString(),
            isPartialWeek: $isPartial,
            daysWithDataTotal: $daysWithDataTotal,
            current: $current,
            prev: $prev,
            managers: $managerRows,
            topMovements: $topMovements,
            topStuck: $topStuck,
        );
    }

    /**
     * Previous week's team agg only (spec §5.2 `prev`), null when the prior week
     * has no snapshots at all (first week).
     *
     * @param  list<User>  $managers
     * @param  list<int>  $pipelineIds
     */
    private function aggregatePrevAgg(
        string $teamName,
        array $managers,
        CarbonImmutable $prevMonday,
        array $pipelineIds,
        ?string $teamChatId,
    ): ?WeeklyAgg {
        $hasAny = $this->weekHasSnapshots($managers, $prevMonday);
        if (! $hasAny) {
            return null;
        }

        // Recurse one level only (prev of prev not needed → pass empty managers to
        // short-circuit). We reuse aggregate() but discard everything except agg.
        $data = $this->aggregate($teamName, $managers, $prevMonday, $pipelineIds, $teamChatId);

        return $data->current;
    }

    /**
     * Accumulate a day's plan + evening into the per-deal trajectory and the
     * unique-lead sets. A deal's first-seen stage stays as `from`; every day
     * overwrites `last_stage` / `last_days_in_stage` / raw texts.
     *
     * @param  array<int, true>  $managerLeadIds
     * @param  array<int, true>  $teamLeadIds
     * @param  array<int, array<string, mixed>>  $trajectories
     */
    private function accumulate(
        User $manager,
        DaySnapshot $plan,
        DaySnapshot $evening,
        array &$managerLeadIds,
        array &$teamLeadIds,
        array &$trajectories,
    ): void {
        $managerName = (string) $manager->full_name;

        // Latest task result / plan text by deal from this day's rows.
        $planTextByDeal = $this->latestTextByDeal($plan->plan, fact: false);
        $resultByDeal = $this->latestTextByDeal($evening->plan, fact: true);

        // days_in_stage by deal from the evening rows (history-derived).
        $daysInStageByDeal = [];
        foreach ($evening->plan as $row) {
            if ($row->dealId !== null) {
                $daysInStageByDeal[$row->dealId] = $row->daysInStage;
            }
        }

        // The morning stage and evening stage of each deal this day.
        foreach ($plan->leadsById as $dealId => $lead) {
            $dealId = (int) $dealId;
            $managerLeadIds[$dealId] = true;
            $teamLeadIds[$dealId] = true;

            $morningStage = $lead['status_id'] ?? null;
            $eveningStage = $evening->leadsById[$dealId]['status_id'] ?? $morningStage;
            $company = $lead['name'] ?? ('#'.$dealId);

            if (! isset($trajectories[$dealId])) {
                $trajectories[$dealId] = [
                    'manager' => $managerName,
                    'company' => (string) $company,
                    'first_stage' => $morningStage !== null ? (int) $morningStage : null,
                    'last_stage' => $eveningStage !== null ? (int) $eveningStage : null,
                    'last_days_in_stage' => $daysInStageByDeal[$dealId] ?? 1,
                    'raw_task_result' => $resultByDeal[$dealId] ?? '',
                    'raw_plan_text' => $planTextByDeal[$dealId] ?? '',
                ];

                continue;
            }

            $traj = &$trajectories[$dealId];
            $traj['last_stage'] = $eveningStage !== null ? (int) $eveningStage : $traj['last_stage'];
            $traj['last_days_in_stage'] = $daysInStageByDeal[$dealId] ?? $traj['last_days_in_stage'];
            if (($resultByDeal[$dealId] ?? '') !== '') {
                $traj['raw_task_result'] = $resultByDeal[$dealId];
            }
            if (($planTextByDeal[$dealId] ?? '') !== '') {
                $traj['raw_plan_text'] = $planTextByDeal[$dealId];
            }
            unset($traj);
        }
    }

    /**
     * Latest non-empty text per deal from a row set. For fact rows we read
     * result_text; for plan rows we read the task text (raw_plan_text).
     *
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, string>
     */
    private function latestTextByDeal(array $rows, bool $fact): array
    {
        $byDeal = [];
        foreach ($rows as $row) {
            if ($row->dealId === null) {
                continue;
            }
            $text = $fact ? ($row->resultText ?? '') : ($row->text);
            if ($text !== '' && $text !== null) {
                $byDeal[$row->dealId] = (string) $text;
            }
        }

        return $byDeal;
    }

    /**
     * top_movements (spec §5.2): deals whose week delta > 0, sorted (-delta,
     * company), top-5. jump = delta >= 2.
     *
     * @param  array<int, array<string, mixed>>  $trajectories
     * @return list<array<string, mixed>>
     */
    private function buildMovements(array $trajectories): array
    {
        $movements = [];

        foreach ($trajectories as $dealId => $traj) {
            $from = $this->stage($traj['first_stage'] ?? null);
            $to = $this->stage($traj['last_stage'] ?? null);

            $delta = $this->classifier->funnelPosition($to) - $this->classifier->funnelPosition($from);
            if ($delta <= 0) {
                continue;
            }

            $fromMeta = StageMeta::forStage($from);
            $toMeta = StageMeta::forStage($to);

            $movements[] = [
                'lead_id' => (int) $dealId,
                'manager' => (string) $traj['manager'],
                'company' => (string) $traj['company'],
                'from' => $from?->code ?? '',
                'to' => $to?->code ?? '',
                'from_name' => $from?->name ?? '',
                'to_name' => $to?->name ?? '',
                'from_emoji' => $fromMeta->emoji,
                'to_emoji' => $toMeta->emoji,
                'delta' => $delta,
                'jump' => $delta >= 2,
                'raw_task_result' => (string) ($traj['raw_task_result'] ?? ''),
            ];
        }

        usort($movements, static fn (array $a, array $b): int => ($b['delta'] <=> $a['delta'])
            ?: ($a['company'] <=> $b['company']));

        return array_slice(array_values($movements), 0, 5);
    }

    /**
     * top_stuck (spec §5.2): deals not in success/lost whose days_in_stage exceeds
     * the stage's weekly SLA, sorted (-days, company), top-5.
     *
     * @param  array<int, array<string, mixed>>  $trajectories
     * @return list<array<string, mixed>>
     */
    private function buildStuck(array $trajectories): array
    {
        $stuck = [];

        foreach ($trajectories as $dealId => $traj) {
            $stage = $this->stage($traj['last_stage'] ?? null);

            if ($this->classifier->isWon($stage) || $this->classifier->isLost($stage)) {
                continue;
            }

            $meta = StageMeta::forStage($stage);
            $days = (int) ($traj['last_days_in_stage'] ?? 1);
            $threshold = $meta->slaWeekly;

            if ($days <= $threshold) {
                continue;
            }

            $stuck[] = [
                'lead_id' => (int) $dealId,
                'manager' => (string) $traj['manager'],
                'company' => (string) $traj['company'],
                'status' => $stage?->code ?? '',
                'status_name' => $stage?->name ?? '',
                'emoji' => $meta->emoji,
                'days' => $days,
                'threshold' => $threshold,
                'raw_plan_text' => (string) ($traj['raw_plan_text'] ?? ''),
            ];
        }

        usort($stuck, static fn (array $a, array $b): int => ($b['days'] <=> $a['days'])
            ?: ($a['company'] <=> $b['company']));

        return array_slice(array_values($stuck), 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trajectories
     */
    private function successCountTotal(array $trajectories): int
    {
        $n = 0;
        foreach ($trajectories as $traj) {
            if ($this->classifier->isWon($this->stage($traj['last_stage'] ?? null))) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trajectories
     */
    private function successCountForManager(array $trajectories, string $managerName): int
    {
        $n = 0;
        foreach ($trajectories as $traj) {
            if (($traj['manager'] ?? '') !== $managerName) {
                continue;
            }
            if ($this->classifier->isWon($this->stage($traj['last_stage'] ?? null))) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Mon..Fri of the week starting at $monday.
     *
     * @return list<CarbonImmutable>
     */
    private function workingDays(CarbonImmutable $monday): array
    {
        $days = [];
        for ($i = 0; $i < 5; $i++) {
            $days[] = $monday->addDays($i);
        }

        return $days;
    }

    /**
     * A partial week = the target week is the CURRENT week and today is before
     * Friday end (not all 5 working days have happened yet — spec §5.2).
     */
    private function isPartialWeek(CarbonImmutable $monday): bool
    {
        $tz = (string) config('salespulse.timezone', 'Asia/Dubai');
        $now = CarbonImmutable::now($tz);
        $friday = $monday->addDays(4)->endOfDay();

        return $now->lessThan($friday) && $now->greaterThanOrEqualTo($monday->startOfDay());
    }

    private function isSkipped(User $manager, CarbonImmutable $day, ?string $teamChatId): bool
    {
        $onDate = $day->toDateString();

        return PulseSkipDay::query()
            ->whereDate('on_date', $onDate)
            ->where(function ($q) use ($manager, $teamChatId): void {
                $q->where('manager_id', $manager->id);
                if ($teamChatId !== null && $teamChatId !== '') {
                    $q->orWhere(function ($w) use ($teamChatId): void {
                        $w->whereNull('manager_id')->where('team_chat_id', $teamChatId);
                    });
                }
            })
            ->exists();
    }

    private function loadSnapshot(User $manager, CarbonImmutable $day, SnapKind $kind): ?DaySnapshot
    {
        $row = PulseSnapshot::query()
            ->where('manager_id', $manager->id)
            ->whereDate('on_date', $day->toDateString())
            ->where('kind', $kind->value)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $row->data ?? [];

        return DaySnapshot::fromArray($data);
    }

    /**
     * @param  list<User>  $managers
     */
    private function weekHasSnapshots(array $managers, CarbonImmutable $monday): bool
    {
        $ids = array_map(static fn (User $m): int => (int) $m->id, $managers);
        if ($ids === []) {
            return false;
        }

        // on_date is date-cast ("Y-m-d 00:00:00"); use whereDate bounds so the
        // Sunday upper bound is not lexically dropped.
        return PulseSnapshot::query()
            ->whereIn('manager_id', $ids)
            ->whereDate('on_date', '>=', $monday->toDateString())
            ->whereDate('on_date', '<=', $monday->addDays(6)->toDateString())
            ->exists();
    }

    /**
     * Prime the stage cache with every stage referenced by the week's snapshots so
     * the trajectory classification is DB-light.
     *
     * @param  list<User>  $managers
     */
    private function primeStageCacheForWeek(array $managers, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $ids = array_map(static fn (User $m): int => (int) $m->id, $managers);
        if ($ids === []) {
            return;
        }

        /** @var Collection<int, PulseSnapshot> $rows */
        $rows = PulseSnapshot::query()
            ->whereIn('manager_id', $ids)
            ->whereDate('on_date', '>=', $from->toDateString())
            ->whereDate('on_date', '<=', $to->toDateString())
            ->get(['id', 'data']);

        $stageIds = [];
        foreach ($rows as $snap) {
            /** @var array<string, mixed> $data */
            $data = $snap->data ?? [];
            /** @var array<string, array<string, mixed>> $leads */
            $leads = $data['leads_by_id'] ?? [];
            foreach ($leads as $lead) {
                $sid = $lead['status_id'] ?? null;
                if ($sid !== null) {
                    $stageIds[(int) $sid] = true;
                }
            }
        }

        $missing = array_values(array_filter(
            array_keys($stageIds),
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
