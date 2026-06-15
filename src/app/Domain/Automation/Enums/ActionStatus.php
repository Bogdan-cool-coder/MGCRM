<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

/**
 * Outcome of a single action-handler execute() call.
 *
 * This is the handler-level vocabulary, distinct from (but mapped onto) the
 * persisted RunStatus. A handler never writes an AutomationRun itself — it
 * returns an ActionResult carrying one of these, and ActionDispatcher finalizes
 * the run with the corresponding RunStatus.
 *
 *  - Success — the side-effect ran (or was synchronously applied).
 *  - Skipped — intentionally not performed (config no-op, field not whitelisted,
 *              empty recipient, …). NOT an error; holds the idempotency slot.
 *  - Queued  — a network side-effect (tg/webhook) was handed to a queue job; the
 *              run is parked as `queued` and the job resolves it later. Holds the
 *              idempotency slot while in flight.
 */
enum ActionStatus: string
{
    case Success = 'success';
    case Skipped = 'skipped';
    case Queued = 'queued';

    /**
     * Map a handler outcome to the persisted run status. The two enums are kept
     * separate so a handler can never reach for `pending`/`failed` directly —
     * `failed` is only ever set by the dispatcher's catch block.
     */
    public function toRunStatus(): RunStatus
    {
        return match ($this) {
            self::Success => RunStatus::Success,
            self::Skipped => RunStatus::Skipped,
            self::Queued => RunStatus::Queued,
        };
    }
}
