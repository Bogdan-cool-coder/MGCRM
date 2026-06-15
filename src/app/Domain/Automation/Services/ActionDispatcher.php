<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Actions\ActionHandler;
use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ActionDispatcher — routes a PipelineAutomation to its ActionHandler and owns
 * the AutomationRun lifecycle around the handler call.
 *
 * It is the seam the trigger sources (P2 inline listeners / cron scanners) call:
 *   - dispatch() claims an idempotency slot (AutomationEngine), runs the handler
 *     inside try/catch, and finalizes the run — success / skipped / queued from
 *     the ActionResult, or failed (slot released) if the handler threw. A
 *     queued result also dispatches the handler's deferred job with the run id.
 *   - dryRun() runs the handler's no-side-effect preview for the test endpoint;
 *     it writes no run.
 *
 * The handler registry is built once from the injected handlers, keyed by
 * ActionKind — adding an action means binding a new handler, no dispatcher edit.
 *
 * Fault isolation: dispatch() never throws to its caller. A scanner looping over
 * many automations must not be killed by one bad rule (try/catch + continue in
 * the caller; this method already swallows handler faults into a `failed` run).
 */
class ActionDispatcher
{
    /** @var array<string, ActionHandler> */
    private array $handlers = [];

    /**
     * @param  iterable<ActionHandler>  $handlers
     */
    public function __construct(
        private readonly AutomationEngine $engine,
        iterable $handlers,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->kind()->value] = $handler;
        }
    }

    /**
     * Claim a slot, run the action synchronously and finalize the run.
     *
     * Returns the persisted AutomationRun, or null when the idempotency slot was
     * already taken (a prior scan / concurrent worker handled this event) — a
     * silent dedup skip with no side-effect and no new row.
     *
     * This is the all-in-one path used by callers that want the action to run on
     * the spot (and by tests). The trigger sources instead use claimAndQueue() so
     * the action runs off the web request / scanner loop.
     */
    public function dispatch(
        PipelineAutomation $automation,
        Deal $target,
        DateTimeInterface $triggerEventTs,
    ): ?AutomationRun {
        $run = $this->engine->claimRunSlot(
            $automation,
            AutomationTargetType::Deal,
            $target->id,
            $triggerEventTs,
        );

        if ($run === null) {
            return null; // deduped — already claimed
        }

        return $this->executeRun($run, $automation, $target);
    }

    /**
     * Claim a slot synchronously, then hand the action off to the `automation`
     * queue. This is the trigger-source path (inline listeners + cron scanners):
     *
     *   1. Claim the `pending` run BEFORE any side-effect, ON the transactional /
     *      scanner thread — so the partial-unique index dedups duplicate events
     *      deterministically (a re-fired event or a re-running scan hits the
     *      conflict here and gets null = silent skip).
     *   2. Dispatch ExecuteAutomationActionJob with the run id, so the actual
     *      action (Telegram / webhook / doc IO included) runs in the queue worker,
     *      never blocking the web request or the scan loop.
     *
     * Returns the claimed run (still `pending`, the job resolves it later), or
     * null when the slot was already taken (deduped — no job dispatched).
     */
    public function claimAndQueue(
        PipelineAutomation $automation,
        Deal $target,
        DateTimeInterface $triggerEventTs,
    ): ?AutomationRun {
        $run = $this->engine->claimRunSlot(
            $automation,
            AutomationTargetType::Deal,
            $target->id,
            $triggerEventTs,
        );

        if ($run === null) {
            return null; // deduped — already claimed, no job
        }

        ExecuteAutomationActionJob::dispatch($run->id);

        return $run;
    }

    /**
     * Dry-run preview (no side-effect, no run written) for the test endpoint.
     */
    public function dryRun(PipelineAutomation $automation, Deal $target): ActionPreview
    {
        $handler = $this->handlers[$automation->action_kind->value] ?? null;

        if ($handler === null) {
            return ActionPreview::wont("No handler for action '{$automation->action_kind->value}'.");
        }

        return $handler->dryRun($automation, $target, $automation->action_config ?? []);
    }

    /**
     * Run the handler against an already-claimed `pending` run and finalize it.
     *
     * Public so ExecuteAutomationActionJob can call it after re-reading the run
     * off the queue. NEVER re-throws: a handler fault becomes a `failed` run
     * (deterministic terminal state), so a scanner loop or queue worker is never
     * killed by one bad rule.
     */
    public function executeRun(AutomationRun $run, PipelineAutomation $automation, Deal $target): AutomationRun
    {
        $handler = $this->handlers[$automation->action_kind->value] ?? null;

        if ($handler === null) {
            return $this->engine->finalize(
                $run,
                RunStatus::Skipped,
                ['summary' => "No handler for action '{$automation->action_kind->value}'."],
            );
        }

        try {
            $result = $handler->execute($automation, $target, $automation->action_config ?? []);

            $finalized = $this->engine->finalize(
                $run,
                $result->status->toRunStatus(),
                $result->toArray(),
            );

            // Network actions: now that the run id exists, dispatch the deferred
            // job. The run stays `queued` until the job resolves it.
            if ($result->status === ActionStatus::Queued && $result->deferredJobFactory !== null) {
                dispatch(($result->deferredJobFactory)($finalized->id));
            }

            $automation->forceFill(['last_run_at' => now()])->save();

            return $finalized;
        } catch (Throwable $e) {
            Log::warning('Automation action failed', [
                'automation_id' => $automation->id,
                'action_kind' => $automation->action_kind->value,
                'target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return $this->engine->finalize(
                $run,
                RunStatus::Failed,
                null,
                mb_substr($e->getMessage(), 0, 2000),
            );
        }
    }
}
