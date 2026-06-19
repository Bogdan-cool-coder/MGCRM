<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * WeeklyData — the full weekly aggregation result (port of the AMO bot's
 * weekly.py output, spec §5.2). It is the payload source for the weekly LLM call
 * AND for the offline rendering, so it carries everything the §5.2 payload shape
 * needs:
 *
 *   { team, week, is_partial_week, days_with_data_total, current:<agg>, prev:<agg|null>,
 *     managers:[{name,days_with_plan,days_skipped,activity_pct,done,plan,
 *                status_updates,leads,success,lost,status_downgrades}],
 *     top_movements:[{lead_id,manager,company,from,to,delta,jump,raw_task_result}],
 *     top_stuck:[{lead_id,manager,company,status,days,threshold,raw_plan_text}] }
 *
 * `from`/`to`/`status` on movements/stuck are the stage CODEs (so the renderer
 * resolves emoji + name); `from`/`to`/`status` *names + emoji* are carried too so
 * the offline path needs no DB. Immutable VO.
 *
 * @phpstan-type ManagerRow array{name: string, days_with_plan: int, days_skipped: int, activity_pct: int, done: int, plan: int, status_updates: int, leads: int, success: int, lost: int, status_downgrades: int}
 * @phpstan-type MovementRow array{lead_id: int, manager: string, company: string, from: string, to: string, from_emoji: string, to_emoji: string, delta: int, jump: bool, raw_task_result: string}
 * @phpstan-type StuckRow array{lead_id: int, manager: string, company: string, status: string, emoji: string, days: int, threshold: int, raw_plan_text: string}
 */
final readonly class WeeklyData
{
    /**
     * @param  list<ManagerRow>  $managers
     * @param  list<MovementRow>  $topMovements
     * @param  list<StuckRow>  $topStuck
     */
    public function __construct(
        public string $team,
        public string $week,
        public bool $isPartialWeek,
        public int $daysWithDataTotal,
        public WeeklyAgg $current,
        public ?WeeklyAgg $prev,
        public array $managers,
        public array $topMovements,
        public array $topStuck,
    ) {}

    /**
     * The §5.2 LLM payload shape (snake_case, names/emoji stripped from movements/
     * stuck where the spec uses bare from/to/status names — see fromName/toName).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'team' => $this->team,
            'week' => $this->week,
            'is_partial_week' => $this->isPartialWeek,
            'days_with_data_total' => $this->daysWithDataTotal,
            'current' => $this->current->toArray(),
            'prev' => $this->prev?->toArray(),
            'managers' => $this->managers,
            'top_movements' => array_map(static fn (array $m): array => [
                'lead_id' => $m['lead_id'],
                'manager' => $m['manager'],
                'company' => $m['company'],
                'from' => $m['from'],
                'to' => $m['to'],
                'delta' => $m['delta'],
                'jump' => $m['jump'],
                'raw_task_result' => $m['raw_task_result'],
            ], $this->topMovements),
            'top_stuck' => array_map(static fn (array $s): array => [
                'lead_id' => $s['lead_id'],
                'manager' => $s['manager'],
                'company' => $s['company'],
                'status' => $s['status'],
                'days' => $s['days'],
                'threshold' => $s['threshold'],
                'raw_plan_text' => $s['raw_plan_text'],
            ], $this->topStuck),
        ];
    }
}
