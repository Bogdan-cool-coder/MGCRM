<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

/**
 * AutomationRun lifecycle status.
 *
 * - pending  — slot claimed (row inserted) BEFORE the side-effect runs.
 * - queued   — a network action was deferred to a background job; holds the
 *              idempotency slot until the job resolves it (success/failed/skipped).
 * - success  — action executed; idempotency slot is held (no re-run).
 * - skipped  — action intentionally not performed (e.g. config no-op); slot held.
 * - failed   — action raised; the idempotency slot is RELEASED (trigger_event_ts
 *              nulled) so a retry / next scan can re-claim it.
 *
 * holdsIdemSlot() encodes the dedup contract mirrored from contracts'
 * should_release_idem_slot: every terminal status except `failed` holds the slot.
 */
enum RunStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Success = 'success';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /**
     * Whether this status keeps the idempotency slot reserved.
     *
     * success / skipped / queued hold the slot (a concurrent tick or replica
     * will hit the unique conflict and not duplicate the action). `failed`
     * releases it so the action can be retried.
     */
    public function holdsIdemSlot(): bool
    {
        return match ($this) {
            self::Success, self::Skipped, self::Queued => true,
            self::Pending, self::Failed => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Skipped, self::Failed => true,
            self::Pending, self::Queued => false,
        };
    }
}
