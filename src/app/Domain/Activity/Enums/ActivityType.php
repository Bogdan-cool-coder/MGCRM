<?php

declare(strict_types=1);

namespace App\Domain\Activity\Enums;

/**
 * ActivityType — kinds of activity (call/meeting/task/note).
 *
 * Introduced in S1.5 as a stable contract for PipelineStage.task_types (the
 * per-stage whitelist of allowed activity kinds). The full Activity domain
 * (models/services) lands in S1.6 and reuses this enum.
 */
enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Task = 'task';
    case Note = 'note';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
