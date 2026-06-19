<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Enums;

/**
 * SkipKind — what a pulse_skip_days row represents (spec §3 / §8):
 *
 *   - Skip:     a single day off (/skipday). Persisted per-day; a team-wide skip
 *               carries no manager_id, a personal skip sets manager_id.
 *   - Vacation: a multi-day absence (/vacation). Stored as ONE row per covered
 *               day for the manager, all carrying the same vacation_until end date
 *               so /progress can render "🌴 отпуск до DD.MM" and the scheduler can
 *               detect "returning from vacation" on the first day back.
 *
 * The port of skips.py: the original tracked vacation by writing consecutive
 * personal skip rows; we keep that shape but tag the kind so the two commands
 * (/unskipday vs /unvacation) and the /progress label stay distinguishable.
 */
enum SkipKind: string
{
    case Skip = 'skip';
    case Vacation = 'vacation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
