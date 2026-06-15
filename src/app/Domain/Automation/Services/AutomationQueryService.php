<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use Illuminate\Database\Eloquent\Collection;

/**
 * AutomationQueryService (M7 P4) — composes the automation list query for
 * GET /api/automations from the index filters.
 *
 * Query composition lives here, not the controller/model (ARCHITECTURE §1). The
 * list eager-loads pipeline/stage for the denormalised names the builder table
 * shows and withCount('runs') for the per-rule run badge, both surfaced
 * conditionally by AutomationResource.
 */
class AutomationQueryService
{
    /**
     * @param  array{pipeline_id?: int|null, stage_id?: int|null, trigger_kind?: TriggerKind|null, is_active?: bool|null}  $filters
     * @return Collection<int, PipelineAutomation>
     */
    public function list(array $filters): Collection
    {
        $query = PipelineAutomation::query()
            ->with(['pipeline:id,name', 'stage:id,name'])
            ->withCount('runs')
            ->orderByDesc('id');

        if (! empty($filters['pipeline_id'])) {
            $query->where('pipeline_id', $filters['pipeline_id']);
        }

        // stage_id filter: a concrete stage matches its own stage-scoped rules AND
        // the whole-pipeline rules (stage_id NULL) that also fire on that stage.
        if (! empty($filters['stage_id'])) {
            $stageId = (int) $filters['stage_id'];
            $query->where(function ($q) use ($stageId): void {
                $q->where('stage_id', $stageId)->orWhereNull('stage_id');
            });
        }

        if (! empty($filters['trigger_kind']) && $filters['trigger_kind'] instanceof TriggerKind) {
            $query->where('trigger_kind', $filters['trigger_kind']->value);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->get();
    }
}
