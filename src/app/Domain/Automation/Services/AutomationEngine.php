<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AutomationEngine — resolve + idempotency + run lifecycle (P0 core).
 *
 * This is the orchestration spine the action handlers (P1) and trigger
 * sources (P2 inline listeners / cron scanners) build on. It owns three things:
 *
 *  - resolveFor(): which active automations match a (pipeline, stage|NULL,
 *    trigger) tuple.
 *  - claimRunSlot(): insert a `pending` AutomationRun BEFORE the side-effect,
 *    returning null if the slot is already taken (the partial-unique index
 *    rejected the duplicate) — the idempotency guard, mirrored from contracts'
 *    claim_run_slot.
 *  - finalize(): move a run to a terminal status; on `failed` it RELEASES the
 *    idempotency slot (nulls trigger_event_ts) so a retry / next scan can
 *    re-claim it, mirroring should_release_idem_slot.
 */
class AutomationEngine
{
    /**
     * Active automations matching the (pipeline, stage|NULL, trigger) tuple.
     *
     * stage_id IS NULL automations (whole-pipeline) match regardless of the
     * concrete stage; a stage-scoped automation matches only its stage. The
     * query lives here, not the model (ARCHITECTURE §1).
     *
     * @return Collection<int, PipelineAutomation>
     */
    public function resolveFor(TriggerKind $trigger, int $pipelineId, ?int $stageId): Collection
    {
        return PipelineAutomation::query()
            ->where('is_active', true)
            ->where('pipeline_id', $pipelineId)
            ->where('trigger_kind', $trigger->value)
            ->where(function ($q) use ($stageId): void {
                $q->whereNull('stage_id');
                if ($stageId !== null) {
                    $q->orWhere('stage_id', $stageId);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Claim an execution slot by inserting a `pending` run BEFORE the side-effect.
     *
     * Returns the created run if the slot is ours (proceed to run the action),
     * or null if the partial-unique index ux_automation_runs_idem rejected the
     * insert — meaning this (automation, target, trigger_event_ts) was already
     * claimed by a prior scan / concurrent worker. Null = silent idempotent skip.
     *
     * trigger_event_ts is required: the dedup window is keyed on it. Inline
     * callers pass the event timestamp (e.g. stage_changed_at / created_at);
     * cron scanners pass a deterministic window timestamp.
     */
    public function claimRunSlot(
        PipelineAutomation $automation,
        AutomationTargetType $targetType,
        int $targetId,
        DateTimeInterface $triggerEventTs
    ): ?AutomationRun {
        try {
            return AutomationRun::query()->create([
                'automation_id' => $automation->id,
                'target_type' => $targetType->value,
                'target_id' => $targetId,
                'status' => RunStatus::Pending->value,
                'trigger_event_ts' => $triggerEventTs,
                'started_at' => now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Slot already taken by a prior run — idempotent skip.
                return null;
            }

            throw $e;
        }
    }

    /**
     * Move a run to a terminal status and stamp finished_at.
     *
     * On `failed` the idempotency slot is released (trigger_event_ts nulled) so a
     * manual retry or the next scan can re-claim it — mirroring
     * should_release_idem_slot. success / skipped / queued hold the slot.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function finalize(
        AutomationRun $run,
        RunStatus $status,
        ?array $result = null,
        ?string $errorMessage = null
    ): AutomationRun {
        $run->status = $status;
        $run->result = $result;
        $run->error_message = $errorMessage;
        $run->finished_at = now();

        if ($this->shouldReleaseIdemSlot($status, $run->trigger_event_ts !== null)) {
            $run->trigger_event_ts = null;
        }

        $run->save();

        return $run;
    }

    /**
     * Pure dedup decision: release the idempotency slot only for a `failed`
     * terminal status that actually held one (trigger_event_ts non-null).
     *
     * Manual runs (no trigger_event_ts) have no slot to release. success /
     * skipped / queued hold their slot (no duplicate on retry/next tick).
     */
    public function shouldReleaseIdemSlot(RunStatus $status, bool $hasTriggerEventTs): bool
    {
        if (! $hasTriggerEventTs) {
            return false;
        }

        return ! $status->holdsIdemSlot();
    }

    /**
     * Defensive safety net: re-dispatch the execution job for runs stuck in
     * `pending` past $olderThanMinutes.
     *
     * A `pending` run is only ever transient — the slot is claimed synchronously,
     * then ExecuteAutomationActionJob resolves it to a terminal status within
     * seconds. A run left `pending` means the job never ran to completion: a worker
     * that claimed the slot then died before/while running the job (OOM/SIGTERM),
     * a queue that dropped the message, or the historical pre-commit dispatch race
     * (a worker picked up the job before COMMIT, found no committed deal, and the
     * run was never resolved). Left alone that run holds its idempotency slot
     * forever and the action is lost silently.
     *
     * The sweep re-dispatches ExecuteAutomationActionJob for each stale run. That
     * job is idempotent by construction: it is ShouldBeUnique on the run id and
     * BAILS if the run is no longer `pending`, so re-dispatching a run that a slow
     * worker is about to finish is a no-op, never a double side-effect. Runs whose
     * target deal has since vanished are finalized `skipped` by the job.
     *
     * The age floor ($olderThanMinutes, default from config) MUST exceed the job's
     * wall-clock timeout so an in-flight (legitimately still-running) job is never
     * swept out from under itself. Returns the number of runs re-dispatched.
     */
    public function sweepStalePending(?int $olderThanMinutes = null): int
    {
        $minutes = max(1, $olderThanMinutes ?? (int) config('automation.stale_pending_minutes', 15));
        $cutoff = Carbon::now()->subMinutes($minutes);

        $staleIds = AutomationRun::query()
            ->where('status', RunStatus::Pending->value)
            ->where('started_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        foreach ($staleIds as $runId) {
            ExecuteAutomationActionJob::dispatch((int) $runId);
        }

        return $staleIds->count();
    }

    /**
     * Detect a unique-constraint violation across PostgreSQL (SQLSTATE 23505)
     * and SQLite (constraint-failed message), so claimRunSlot stays portable
     * between the live DB and the :memory: test driver.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (($e->getCode() ?? null) === '23505') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || (str_contains($message, 'constraint failed') && str_contains($message, 'unique'));
    }
}
