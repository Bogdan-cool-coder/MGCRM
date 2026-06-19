<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Enums;

/**
 * AnnouncedEventType — the two events the announcer posts to the team chat
 * (spec §4): a closed first-time meeting (`meeting_done`) and a deal moving into
 * a won stage (`success`).
 *
 * On MGCRM the source differs from AMO: meeting_done is a completed FTM Activity
 * (is_first_time_meeting + conditions); success is a DealStageHistory transition
 * into an is_won stage — NOT a task type. Both are de-duplicated by the unique
 * `pulse_announced_events.activity_id` key.
 */
enum AnnouncedEventType: string
{
    case MeetingDone = 'meeting_done';
    case Success = 'success';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
