<?php

declare(strict_types=1);

namespace App\Domain\Activity\Enums;

/**
 * ActivityType — kinds of activity (call/meeting/task/note/follow_up).
 *
 * Introduced in S1.5 as a stable contract for PipelineStage.task_types (the
 * per-stage whitelist of allowed activity kinds). The full Activity domain
 * (models/services) lands in S1.6 and reuses this enum.
 *
 * `follow_up` is additive (Deals redesign — Kanban §1.4 / my-tasks board §4.5):
 * a distinct kind for "вернуться к клиенту" tasks rendered with its own icon on
 * the Kanban card and a coloured tag on the personal task board. Adding it does
 * not change existing rows (the column is a plain string) — fully backward
 * compatible. It is task-like for scheduling (carries a due_at, can be completed).
 */
enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Task = 'task';
    case Note = 'note';
    case FollowUp = 'follow_up';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Task-like kinds carry a deadline and a "next task" health signal on a deal
     * (call/meeting/task/follow_up). A note is documentation only — it never
     * surfaces as next_task. Single-sourced so the board enrichment, the
     * "deals without tasks" widget and the my-tasks board stay in lockstep.
     *
     * @return list<string>
     */
    public static function taskLikeValues(): array
    {
        return [
            self::Call->value,
            self::Meeting->value,
            self::Task->value,
            self::FollowUp->value,
        ];
    }
}
