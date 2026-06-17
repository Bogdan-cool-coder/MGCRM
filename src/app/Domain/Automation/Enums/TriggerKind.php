<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

/**
 * Automation trigger kinds (PipelineAutomation.trigger_kind).
 *
 * Two execution paths, decided by isInline()/isCron():
 * - inline (on_enter_stage, on_create) — fired synchronously from a Sales event
 *   listener AFTER the originating transaction commits.
 * - cron (idle_in_stage_days, date_field_approaching) — fired by a scheduled
 *   scanner command; idempotency is enforced by the partial-unique AutomationRun
 *   index.
 *
 * MVP set (4 triggers). field_value_changed / activity_completed are a later
 * phase (see backend plan §9).
 */
enum TriggerKind: string
{
    case OnEnterStage = 'on_enter_stage';
    case OnCreate = 'on_create';
    case IdleInStageDays = 'idle_in_stage_days';
    case DateFieldApproaching = 'date_field_approaching';

    /**
     * Inline triggers fire synchronously from a domain event after commit.
     */
    public function isInline(): bool
    {
        return match ($this) {
            self::OnEnterStage, self::OnCreate => true,
            self::IdleInStageDays, self::DateFieldApproaching => false,
        };
    }

    /**
     * Cron triggers fire from a scheduled scanner command.
     */
    public function isCron(): bool
    {
        return ! $this->isInline();
    }
}
