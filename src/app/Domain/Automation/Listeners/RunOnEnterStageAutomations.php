<?php

declare(strict_types=1);

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Events\DealStageChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunOnEnterStageAutomations (M7 P2) — fires `on_enter_stage` automations when a
 * deal actually moves to a new stage.
 *
 * Subscribes to Sales\Events\DealStageChanged (dispatched by DealMoveService::move
 * AFTER the move transaction commits — only on a real transition; no-op and
 * rolled-back moves never emit). It resolves on_enter_stage automations scoped to
 * the destination stage (toStageId) PLUS whole-pipeline rules (stage_id IS NULL),
 * exactly what AutomationEngine::resolveFor does when given a concrete stage.
 *
 * trigger_event_ts = event.occurredAt (the post-commit ISO timestamp): the
 * natural dedup key. A replayed DealStageChanged for the same move re-claims the
 * same slot and is deduped; a genuine later re-entry into the stage has a new
 * timestamp and fires again — the intended behaviour.
 *
 * Does NO network IO: claimAndQueue queues the action so the web request that
 * moved the deal is never blocked. Registered in AppServiceProvider::boot.
 */
class RunOnEnterStageAutomations
{
    public function __construct(
        private readonly AutomationEngine $engine,
        private readonly ActionDispatcher $dispatcher,
    ) {}

    public function handle(DealStageChanged $event): void
    {
        $deal = $event->deal;

        $automations = $this->engine->resolveFor(
            TriggerKind::OnEnterStage,
            (int) $deal->pipeline_id,
            $event->toStageId,
        );

        if ($automations->isEmpty()) {
            return;
        }

        $eventTs = $this->parseOccurredAt($event->occurredAt);

        foreach ($automations as $automation) {
            try {
                $this->dispatcher->claimAndQueue($automation, $deal, $eventTs);
            } catch (Throwable $e) {
                Log::warning('RunOnEnterStageAutomations: failed to enqueue automation', [
                    'automation_id' => $automation->id,
                    'deal_id' => $deal->id,
                    'to_stage_id' => $event->toStageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Parse the event's ISO-8601 occurredAt into a Carbon instance; fall back to
     * now() if it is somehow unparsable, so a malformed timestamp never aborts
     * the whole stage-change reaction.
     */
    private function parseOccurredAt(string $occurredAt): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }
}
