<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Enums;

/**
 * SnapKind — which half of a manager's day a pulse snapshot captures.
 *
 * Port of the AMO oversight bot's snapshot dichotomy (spec §2): the morning
 * PLAN is write-once (immutable list of the day's tasks), the evening FACT is an
 * upsert (re-collected `collect_day`). The (manager, on_date, kind) unique key
 * on `pulse_snapshots` enforces exactly one PLAN and one FACT per manager-day.
 */
enum SnapKind: string
{
    case Plan = 'plan';
    case Fact = 'fact';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
