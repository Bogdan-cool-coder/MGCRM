<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * WeeklyAgg — the team-level aggregate for one week (spec §5.2 `agg` shape):
 *   { activity_pct, done, plan, status_update_pct, status_updates, unique_leads,
 *     success, lost, status_downgrades, extra_tasks }
 *
 * `current` is this week's agg, `prev` is last week's (null on the first week).
 * pct fields are recomputed from the summed numerators/denominators (NOT averaged
 * over days) so they match the daily metric definition (spec §1.2).
 *
 * Immutable VO. toArray() emits the exact snake_case keys the weekly LLM payload
 * expects (spec §5.2).
 */
final readonly class WeeklyAgg
{
    public function __construct(
        public int $done,
        public int $plan,
        public int $statusUpdates,
        public int $uniqueLeads,
        public int $success,
        public int $lost,
        public int $statusDowngrades,
        public int $extraTasks,
    ) {}

    public function activityPct(): int
    {
        return $this->pct($this->done, $this->plan);
    }

    public function statusUpdatePct(): int
    {
        return $this->pct($this->statusUpdates, $this->uniqueLeads);
    }

    private function pct(int $x, int $y): int
    {
        if ($y === 0) {
            return 0;
        }

        return (int) round($x * 100 / $y);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'activity_pct' => $this->activityPct(),
            'done' => $this->done,
            'plan' => $this->plan,
            'status_update_pct' => $this->statusUpdatePct(),
            'status_updates' => $this->statusUpdates,
            'unique_leads' => $this->uniqueLeads,
            'success' => $this->success,
            'lost' => $this->lost,
            'status_downgrades' => $this->statusDowngrades,
            'extra_tasks' => $this->extraTasks,
        ];
    }
}
