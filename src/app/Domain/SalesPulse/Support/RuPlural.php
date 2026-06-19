<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Support;

/**
 * RuPlural — Russian pluralization for the SalesPulse report layer (spec §7).
 *
 * Ports the AMO bot's `days_str` / `tasks_str` helpers 1-for-1. The selection
 * rule (the standard Russian three-form rule) is:
 *
 *   n % 100 in 11..14          → many  (дней / задач)
 *   n %  10 == 1               → one   (день / задача)
 *   n %  10 in 2..4            → few   (дня / задачи)
 *   else                       → many  (дней / задач)
 *
 * The teen exception (11..14) is checked first so "11 дней" / "112 дней" win over
 * the last-digit rule. The number itself is prefixed by the caller-rendered form
 * ("{n} {days_str(n)}") — pick() returns only the noun, dayStr()/taskStr() return
 * the full "{n} {noun}" phrase used in the bot messages.
 *
 * Pure static helper — no DB, no config. Thin by design (spec §7: reuse if RU
 * plural exists, else a thin Support helper — none existed in MGCRM).
 */
final class RuPlural
{
    /**
     * Pick the correct of three Russian forms for a count.
     *
     * @param  string  $one  form for 1   (день / задача)
     * @param  string  $few  form for 2-4 (дня / задачи)
     * @param  string  $many  form for 0,5-20 (дней / задач)
     */
    public static function pick(int $n, string $one, string $few, string $many): string
    {
        $abs = abs($n);
        $mod100 = $abs % 100;
        $mod10 = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        if ($mod10 === 1) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }

    /**
     * "{n} день|дня|дней" (spec §7 days_str).
     */
    public static function days(int $n): string
    {
        return $n.' '.self::pick($n, 'день', 'дня', 'дней');
    }

    /**
     * "{n} задача|задачи|задач" (spec §7 tasks_str).
     */
    public static function tasks(int $n): string
    {
        return $n.' '.self::pick($n, 'задача', 'задачи', 'задач');
    }
}
