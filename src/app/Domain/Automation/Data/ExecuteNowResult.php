<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;

/**
 * Immutable result of a MANUAL execution (M7) — what running the automation right
 * now actually did, with real side-effects (unlike DryRunResult).
 *
 * Produced by AutomationTestService::executeNow() and surfaced by the /execute
 * endpoint. Three numbers + the rows:
 *
 *  - executed: deals for which a fresh AutomationRun was claimed and the action
 *    ran (success / skipped-by-config / queued network job — all counted here as
 *    "we acted on this deal this call").
 *  - skipped: deals whose idempotency slot was already held (a prior run or a
 *    concurrent worker) — claimRunSlot returned null, no row, no side-effect.
 *  - runs: the AutomationRun rows created this call, for the controller to render
 *    through AutomationRunResource so the UI shows exactly what happened.
 */
final readonly class ExecuteNowResult
{
    /**
     * @param  list<AutomationRun>  $runs
     */
    public function __construct(
        public PipelineAutomation $automation,
        public int $executed,
        public int $skipped,
        public array $runs,
    ) {}
}
