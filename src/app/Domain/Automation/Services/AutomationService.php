<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

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
}
