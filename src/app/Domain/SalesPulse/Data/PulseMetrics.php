<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * PulseMetrics — the six /finishday metrics (port of the AMO bot's metrics.py,
 * spec §1.2). Computed once by MetricsService and rendered verbatim.
 *
 *   1. Активность   done / total (+pct)
 *   2. Update статуса updates / companies (+pct)
 *   3. Пропущено     missed
 *   4. Внеплановые    extra
 *   5. Downgrade      statusDowngrades
 *   6. Lost           losts
 *
 * pct = round(x*100/y), 0 when y == 0 (spec §1.2). render() reproduces the bot's
 * message block byte-for-byte (spec §1.2 verbatim).
 *
 * Immutable VO — no DB, no side effects.
 */
final readonly class PulseMetrics
{
    public function __construct(
        public int $activityDone,
        public int $activityTotal,
        public int $statusUpdates,
        public int $companies,
        public int $missed,
        public int $extraTasks,
        public int $statusDowngrades,
        public int $losts,
    ) {}

    /**
     * Activity completion percent — round(done*100/total), 0 when total == 0.
     */
    public function activityPct(): int
    {
        return self::pct($this->activityDone, $this->activityTotal);
    }

    /**
     * Status-update percent — round(updates*100/companies), 0 when companies == 0.
     */
    public function statusUpdatePct(): int
    {
        return self::pct($this->statusUpdates, $this->companies);
    }

    /**
     * round(x*100/y), 0 when y == 0 (spec §1.2). Ported from Python round() which
     * is banker's rounding, but the bot only feeds whole non-negative integers so
     * half-cases never carry a fractional tail here — PHP_ROUND_HALF_UP matches.
     */
    private static function pct(int $x, int $y): int
    {
        if ($y === 0) {
            return 0;
        }

        return (int) round($x * 100 / $y);
    }

    /**
     * Verbatim render of the /finishday metrics block (spec §1.2).
     */
    public function render(): string
    {
        return "📊 Показатели:\n"
            ."  Активность: {$this->activityDone} / {$this->activityTotal} = {$this->activityPct()}%\n"
            ."  Update статуса: {$this->statusUpdates} / {$this->companies} = {$this->statusUpdatePct()}%\n"
            ."  Пропущено: {$this->missed}\n"
            ."  Внеплановые: {$this->extraTasks}\n"
            ."  Downgrade статуса: {$this->statusDowngrades}\n"
            ."  Lost: {$this->losts}";
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'activity_done' => $this->activityDone,
            'activity_total' => $this->activityTotal,
            'activity_pct' => $this->activityPct(),
            'status_updates' => $this->statusUpdates,
            'companies' => $this->companies,
            'status_update_pct' => $this->statusUpdatePct(),
            'missed' => $this->missed,
            'extra_tasks' => $this->extraTasks,
            'status_downgrades' => $this->statusDowngrades,
            'losts' => $this->losts,
        ];
    }
}
