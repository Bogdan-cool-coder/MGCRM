<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * ProgressLine — one manager's /progress line state (spec §6.1). The renderer
 * turns it into the final "{name_link} = ..." string; the service computes which
 * variant applies and (for the live variant) the four counters.
 *
 * Variants (mutually exclusive, checked in this order by the service):
 *   - Vacation : on vacation → "🌴 отпуск до {DD.MM}"
 *   - Skip     : team/manager skipped today → "⏸ скип"
 *   - NoPlan   : no morning PLAN was fixed → "плана нет (/startday не было)"
 *   - Zero     : plan fixed but total == 0 → "0/0"
 *   - Live     : the normal "{done}/{total}{suffix}" with the four counters.
 *
 * Immutable VO. The name + name_link are carried so the renderer is a pure
 * string assembler.
 */
final readonly class ProgressLine
{
    public const VARIANT_VACATION = 'vacation';

    public const VARIANT_SKIP = 'skip';

    public const VARIANT_NO_PLAN = 'no_plan';

    public const VARIANT_ZERO = 'zero';

    public const VARIANT_LIVE = 'live';

    public function __construct(
        public string $variant,
        public string $name,
        public string $nameLink,
        public int $done = 0,
        public int $total = 0,
        public int $postponed = 0,
        public int $notesCount = 0,
        public int $inProgress = 0,
        public ?string $vacationUntil = null,
    ) {}

    public static function vacation(string $name, string $nameLink, string $until): self
    {
        return new self(self::VARIANT_VACATION, $name, $nameLink, vacationUntil: $until);
    }

    public static function skip(string $name, string $nameLink): self
    {
        return new self(self::VARIANT_SKIP, $name, $nameLink);
    }

    public static function noPlan(string $name, string $nameLink): self
    {
        return new self(self::VARIANT_NO_PLAN, $name, $nameLink);
    }

    public static function zero(string $name, string $nameLink): self
    {
        return new self(self::VARIANT_ZERO, $name, $nameLink, total: 0);
    }

    public static function live(
        string $name,
        string $nameLink,
        int $done,
        int $total,
        int $postponed,
        int $notesCount,
        int $inProgress,
    ): self {
        return new self(
            self::VARIANT_LIVE,
            $name,
            $nameLink,
            done: $done,
            total: $total,
            postponed: $postponed,
            notesCount: $notesCount,
            inProgress: $inProgress,
        );
    }
}
