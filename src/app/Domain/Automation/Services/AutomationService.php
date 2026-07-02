<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;

/**
 * AutomationService (M7 P4) — create / update / delete a PipelineAutomation.
 *
 * Thin write service so the controller stays a pass-through (ARCHITECTURE §1):
 * it owns the persistence of the validated payload and the created_by stamp.
 * Trigger/action config validation is the FormRequest's job; resolve/execution
 * is the engine's — this layer only mutates the row.
 */
class AutomationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, ?int $createdByUserId): PipelineAutomation
    {
        $payload['created_by_user_id'] = $createdByUserId;

        return PipelineAutomation::query()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload  only the keys to change (partial)
     */
    public function update(PipelineAutomation $automation, array $payload): PipelineAutomation
    {
        $automation->update($payload);

        return $automation->refresh();
    }

    public function delete(PipelineAutomation $automation): void
    {
        // automation_runs cascade-delete via the FK (see migration).
        $automation->delete();
    }

    /**
     * Purge the whole Automation category for a selective system reset.
     *
     * This is the owning-domain seam the cross-domain SystemResetService
     * (App\Support\System) calls for the `automations` category — the contract's
     * allow-list rule: Support never touches Automation tables directly, it
     * invokes this method (docs/contracts/system-reset-api-contract.md §7, §10.10).
     *
     * Scope (contract §1, category 8 — verified line-by-line against the live
     * schema): the two tables this domain owns, deepest child first:
     *   1. automation_runs   (child — FK automation_id -> pipeline_automations)
     *   2. pipeline_automations (parent — the configured rules)
     * `forms` (webforms) are DELIBERATELY EXCLUDED (product decision P4): they are
     * integration/intake config owned by Domain\Inbox, not an automation rule, and
     * are not this domain's table. Sequences / sequence-runs / bulk-tasks have no
     * tables in the current schema, so there is nothing else to sweep here.
     *
     * FK ordering (contract §3a): automation_runs.automation_id is cascadeOnDelete,
     * but children are still deleted explicitly first so the behaviour is
     * deterministic on both PostgreSQL and the SQLite test suite (SQLite FK
     * enforcement differs), independent of the cascade.
     *
     * Boundaries (contract §5, §7):
     *   - NO authorization here — the `system-reset` gate + confirmation phrase are
     *     enforced upstream in the controller/FormRequest.
     *   - NO own transaction — the orchestrator wraps each category in its own
     *     DB::transaction(), so this method must run inside the caller's tx.
     *
     * @return int rows removed from the category's parent table
     *             (pipeline_automations) — the representative count the reset
     *             report exposes per category (contract §4 meta.deleted, §6.1).
     */
    public function purgeAll(): int
    {
        // Child first (FK-safe, deterministic across drivers).
        AutomationRun::query()->delete();

        return PipelineAutomation::query()->delete();
    }
}
