<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ExecuteAutomationActionJob — runs the action of an already-claimed AutomationRun
 * off the web request, on the dedicated `automation` queue.
 *
 * Both trigger sources (inline Sales-event listeners and the cron scanners) claim
 * the idempotency slot synchronously (insert a `pending` run BEFORE any
 * side-effect — the partial-unique index is the dedup boundary) and then hand the
 * run id to this job. Doing the slot claim eagerly keeps the dedup guarantee on
 * the transactional path; doing the action here keeps Telegram / webhook / doc IO
 * out of both the web request and the scanner loop.
 *
 * Idempotency, mirrored 1-to-1 from Vizion's ProcessChatMessageJob:
 *   - ShouldBeUnique keyed on the run id suppresses an accidental double-dispatch.
 *   - handle() re-reads the run and BAILS if it is no longer `pending` (a prior
 *     run or a manual retry already resolved it), so the action runs at most once
 *     per claimed slot even if the unique TTL lapses or two workers race.
 *   - $tries = 1: a blind queue-level retry would re-run the side-effect. Any
 *     retry the action needs is owned by its deferred network job
 *     (SendAutomationTelegramJob / DispatchAutomationWebhookJob), and a `failed`
 *     run releases the slot so the next scan can re-claim it.
 *
 * The job NEVER re-throws on a handler fault — ActionDispatcher::executeRun()
 * turns it into a `failed` run (deterministic terminal state). failed() is the
 * last-resort hook for an OOM/SIGTERM kill that the inner catch can't observe.
 */
class ExecuteAutomationActionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** No queue-level retry: a re-run would repeat the side-effect. */
    public int $tries = 1;

    public int $timeout = 120;

    /** TTL for the ShouldBeUnique lock; matches the wall-clock timeout. */
    public int $uniqueFor = 120;

    public function __construct(
        private readonly int $runId,
    ) {
        $this->onQueue('automation');
    }

    /**
     * Unique key: one execution job per claimed run.
     */
    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(ActionDispatcher $dispatcher): void
    {
        $run = AutomationRun::with('automation')->find($this->runId);

        if ($run === null) {
            Log::warning('ExecuteAutomationActionJob: run vanished before handle()', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        // Idempotency guard: only a freshly-claimed `pending` run is ours to run.
        // Anything else (already queued/success/skipped/failed) means a prior run
        // or a manual retry handled it — bail without touching the side-effect.
        if ($run->status !== RunStatus::Pending) {
            Log::info('ExecuteAutomationActionJob: skipping — run no longer pending', [
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]);

            return;
        }

        $automation = $run->automation;

        if ($automation === null) {
            // The automation was deleted after the slot was claimed — nothing to
            // run. Mark skipped so the run isn't left dangling in `pending`.
            app(AutomationEngine::class)->finalize(
                $run,
                RunStatus::Skipped,
                ['summary' => 'Automation no longer exists.'],
            );

            return;
        }

        $target = Deal::find($run->target_id);

        if ($target === null) {
            app(AutomationEngine::class)->finalize(
                $run,
                RunStatus::Skipped,
                ['summary' => 'Target deal no longer exists.'],
            );

            return;
        }

        // executeRun() owns the handler call + finalize and never re-throws.
        $dispatcher->executeRun($run, $automation, $target);
    }

    /**
     * Last-resort terminal-state marking if handle() dies in a way the dispatcher
     * can't observe (OOM kill, SIGTERM on timeout, lost DB connection).
     */
    public function failed(Throwable $e): void
    {
        try {
            $run = AutomationRun::find($this->runId);

            if ($run === null || $run->status->isTerminal()) {
                return;
            }

            app(AutomationEngine::class)->finalize(
                $run,
                RunStatus::Failed,
                null,
                mb_substr($e->getMessage(), 0, 2000),
            );
        } catch (Throwable $secondary) {
            Log::error('ExecuteAutomationActionJob::failed() could not record terminal state', [
                'run_id' => $this->runId,
                'primary_error' => $e->getMessage(),
                'secondary_error' => $secondary->getMessage(),
            ]);
        }
    }
}
