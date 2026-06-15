<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\AutomationRunFilter;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * AutomationRunQueryService (M7 P3) — composes the AutomationRun journal query
 * from an AutomationRunFilter for the future GET /api/automation-runs endpoint
 * (P4).
 *
 * Query composition lives here, not the model (ARCHITECTURE §1): the model only
 * exposes thin scopes (forAutomation / forTarget / status / createdBetween /
 * recentFirst), this service decides which to apply based on the filter. It is
 * read-only — no AutomationRun is ever written.
 *
 * The action_kind filter joins to the parent automation via a subquery on
 * pipeline_automations (mirroring contracts' list_runs) so the "failed webhooks"
 * style UI works without N+1, and eager-loads the automation relation for the
 * denormalised name the journal shows.
 */
class AutomationRunQueryService
{
    /**
     * Build the filtered, newest-first journal query (not yet executed).
     *
     * @return Builder<AutomationRun>
     */
    public function query(AutomationRunFilter $filter): Builder
    {
        $query = AutomationRun::query()
            ->with('automation:id,name,action_kind')
            ->recentFirst();

        if ($filter->automationId !== null) {
            $query->forAutomation($filter->automationId);
        }

        if ($filter->targetType !== null && $filter->targetId !== null) {
            $query->forTarget($filter->targetType, $filter->targetId);
        } elseif ($filter->targetType !== null) {
            $query->where('target_type', $filter->targetType->value);
        } elseif ($filter->targetId !== null) {
            $query->where('target_id', $filter->targetId);
        }

        if ($filter->status !== null) {
            $query->status($filter->status);
        }

        if ($filter->actionKind !== null) {
            // Subquery on the parent automations with this action_kind — keeps the
            // base select on automation_runs (no join duplicates), exactly the
            // contracts approach.
            $query->whereIn(
                'automation_id',
                PipelineAutomation::query()
                    ->where('action_kind', $filter->actionKind->value)
                    ->select('id'),
            );
        }

        $query->createdBetween($filter->from, $filter->to);

        return $query;
    }

    /**
     * Paginated journal page (newest-first).
     *
     * @return LengthAwarePaginator<int, AutomationRun>
     */
    public function paginate(AutomationRunFilter $filter, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = max(1, min(200, $perPage));

        return $this->query($filter)->paginate($perPage);
    }
}
