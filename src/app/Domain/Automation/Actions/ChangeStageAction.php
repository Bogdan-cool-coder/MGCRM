<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealMoveService;
use Illuminate\Validation\ValidationException;

/**
 * change_stage — move the deal to another stage in its CURRENT pipeline.
 *
 * Reuses Sales\DealMoveService::move() — the single security boundary for stage
 * changes (row lock, lost/won/required-field gates, history). Cross-pipeline
 * moves are out of MVP scope, so the target stage must belong to the deal's
 * pipeline; otherwise the move is `skipped` (DealMoveService would 422).
 *
 * Loop guard: an on_enter_stage automation whose action is change_stage could
 * recurse. Two defences make this safe without a full cycle detector (deferred):
 *   1. If the deal is already in the target stage, we skip BEFORE calling move()
 *      (move() is itself a no-op there and emits no event).
 *   2. The triggering listener claims an idempotency slot keyed on the event
 *      timestamp, so a re-entrant on_enter_stage for the same move cannot
 *      re-fire the same automation.
 *
 * config: { to_stage_id: int }
 */
final class ChangeStageAction implements ActionHandler
{
    public function __construct(
        private readonly DealMoveService $moves,
    ) {}

    public function kind(): ActionKind
    {
        return ActionKind::ChangeStage;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $toStageId = isset($config['to_stage_id']) ? (int) $config['to_stage_id'] : 0;
        if ($toStageId <= 0) {
            return ActionResult::skipped('to_stage_id is not set.');
        }

        // Loop guard 1: already there → skip (move() would no-op anyway).
        if ((int) $target->stage_id === $toStageId) {
            return ActionResult::skipped('Deal is already in the target stage.');
        }

        $toStage = PipelineStage::query()->find($toStageId);
        if ($toStage === null || (int) $toStage->pipeline_id !== (int) $target->pipeline_id) {
            return ActionResult::skipped('Target stage is not in this deal\'s pipeline.');
        }

        $actorId = (int) ($automation->created_by_user_id ?? $target->owner_user_id ?? 0);

        try {
            $moved = $this->moves->move($target, $toStageId, $actorId);
        } catch (ValidationException $e) {
            // Gate failure (lost-reason / required-fields) under automation — soft skip.
            return ActionResult::skipped(
                'Stage move blocked: '.implode(' ', $e->validator->errors()->all()),
            );
        }

        return ActionResult::success("Moved deal to stage {$toStageId}", [
            'from_stage_id' => $target->getOriginal('stage_id'),
            'to_stage_id' => (int) $moved->stage_id,
        ]);
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $toStageId = isset($config['to_stage_id']) ? (int) $config['to_stage_id'] : 0;
        if ($toStageId <= 0) {
            return ActionPreview::wont('to_stage_id is not set.');
        }

        if ((int) $target->stage_id === $toStageId) {
            return ActionPreview::wont('Deal is already in the target stage.');
        }

        $toStage = PipelineStage::query()->find($toStageId);
        if ($toStage === null || (int) $toStage->pipeline_id !== (int) $target->pipeline_id) {
            return ActionPreview::wont('Target stage is not in this deal\'s pipeline.');
        }

        return ActionPreview::will("Would move deal to stage {$toStageId}", [
            'change_stage' => [
                'from_stage_id' => (int) $target->stage_id,
                'to_stage_id' => $toStageId,
            ],
        ]);
    }
}
