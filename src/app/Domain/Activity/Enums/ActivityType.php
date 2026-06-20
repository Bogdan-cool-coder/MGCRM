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
 *
 * `presentation` is additive too (DealPage 2.0 — «ключевые действия»): a distinct
 * kind for a held product presentation. The deal-card header surfaces the date of
 * the last COMPLETED presentation (last_presentation_at). It is task-like
 * (carries a due_at, can be completed); adding the case does not touch existing
 * rows (plain string column).
 */
enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Task = 'task';
    case Note = 'note';
    case FollowUp = 'follow_up';
    case Presentation = 'presentation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Task-like kinds carry a deadline and a "next task" health signal on a deal
     * (call/meeting/task/follow_up/presentation). A note is documentation only —
     * it never surfaces as next_task. Single-sourced so the board enrichment, the
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
            self::Presentation->value,
        ];
    }

    /**
     * "Touch" kinds — a direct reach-out to the client (звонок / переписка): a
     * call or a follow-up. Powers the deal-card header `last_touch_at` (the date
     * of the last completed touch). Single-sourced so the header and any future
     * touch-based reporting share one definition.
     *
     * @return list<string>
     */
    public static function touchValues(): array
    {
        return [
            self::Call->value,
            self::FollowUp->value,
        ];
    }

    /**
     * "Event" kinds — any client-facing event (звонок / переписка / встреча):
     * touches plus meetings and presentations. Powers the deal-card header
     * `last_event_at` (the date of the last completed event). A superset of
     * touchValues(), single-sourced for the same reason.
     *
     * @return list<string>
     */
    public static function eventValues(): array
    {
        return [
            self::Call->value,
            self::FollowUp->value,
            self::Meeting->value,
            self::Presentation->value,
        ];
    }
}
