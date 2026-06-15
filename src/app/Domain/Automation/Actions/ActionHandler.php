<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;

/**
 * ActionHandler — one automation action (tg_notify / create_task / …).
 *
 * The contract is deliberately small: kind() lets ActionDispatcher build its
 * registry, execute() performs the side-effect and returns an ActionResult, and
 * dryRun() returns an ActionPreview with NO side-effect (for the test endpoint).
 *
 * Handlers receive the already-resolved target (MVP: a Deal) and the validated
 * action_config array; they never write an AutomationRun themselves — the
 * dispatcher owns the run lifecycle. A handler must NOT throw for a config
 * no-op (return ActionResult::skipped); a thrown exception is reserved for a
 * genuine side-effect failure and is turned into a `failed` run by the
 * dispatcher.
 */
interface ActionHandler
{
    public function kind(): ActionKind;

    /**
     * Perform the action's side-effect.
     *
     * @param  array<string, mixed>  $config  validated action_config
     */
    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult;

    /**
     * Preview the action without any side-effect.
     *
     * @param  array<string, mixed>  $config  validated action_config
     */
    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview;
}
