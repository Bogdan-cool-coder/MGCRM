<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use Carbon\CarbonImmutable;

/**
 * DayWindowResolver — single source of the SalesPulse day window (spec §1.1).
 *
 * The window is the calendar day in the configured timezone (default Asia/Dubai):
 *   [00:00:00.000000, 23:59:59.999999]
 *
 * NB (spec §1.1 / port notes): the AMO "+4h on deadline" hack is NOT ported — our
 * Activity.due_at / completed_at are stored TZ-correctly, so day boundaries apply
 * directly to those columns. Both DaySnapshotService (activity filtering) and
 * NotesService (note detection) resolve their window here so they never drift.
 */
class DayWindowResolver
{
    /**
     * Inclusive [from, to] bounds of the day that `$date` falls on, in the pulse
     * timezone. `$date` may be in any zone — only its calendar date is used.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayWindow(CarbonImmutable $date): array
    {
        $tz = $this->timezone();

        $local = $date->setTimezone($tz);

        $from = $local->startOfDay();              // 00:00:00.000000
        $to = $local->endOfDay();                  // 23:59:59.999999

        return [$from, $to];
    }

    public function timezone(): string
    {
        /** @var string $tz */
        $tz = config('salespulse.timezone', 'Asia/Dubai');

        return $tz;
    }
}
