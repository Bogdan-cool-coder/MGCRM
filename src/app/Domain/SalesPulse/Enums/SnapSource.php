<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Enums;

/**
 * SnapSource — how a pulse snapshot (or a daily-status plan/fact slot) was
 * captured: `manual` (a manager ran /startday or /finishday) or `auto` (the
 * scheduler fixed it via the auto_plan / auto_fact job — spec §3).
 *
 * Stored on both `pulse_snapshots.source` and `pulse_daily_status.plan_source` /
 * `fact_source` so reports can flag system-fixed days.
 */
enum SnapSource: string
{
    case Manual = 'manual';
    case Auto = 'auto';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
