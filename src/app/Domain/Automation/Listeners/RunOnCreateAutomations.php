<?php

declare(strict_types=1);

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Events\DealCreated;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunOnCreateAutomations (M7 P2) — fires `on_create` automations when a deal is
 * created.
 *
 * Subscribes to Sales\Events\DealCreated (dispatched by DealService::createInbound
 * AFTER its transaction commits, so the deal is always persisted here). For every
 * active on_create automation matching the deal's pipeline, it claims an
 * idempotency slot and queues the action — it does NO network IO itself, so the
 * inbound flow that created the deal is never blocked.
 *
 * trigger_event_ts = deal.created_at: a stable, deterministic marker so a
 * re-dispatched DealCreated (retry, replay) re-claims the same slot and is
 * deduped by the partial-unique AutomationRun index.
 *
 * Registered in AppServiceProvider::boot via Event::listen.
 */
class RunOnCreateAutomations
{
    public function __construct(
        private readonly AutomationEngine $engine,
        private readonly ActionDispatcher $dispatcher,
    ) {}

    public function handle(DealCreated $event): void
    {
        $deal = $event->deal;

        // on_create is a whole-pipeline trigger — a deal has no "entered" stage
        // history beyond its initial stage, so resolve with stageId = null
        // (only whole-pipeline on_create rules apply; stage-scoped on_create is
        // surfaced via the stage match too, mirroring resolveFor semantics).
        $automations = $this->engine->resolveFor(
            TriggerKind::OnCreate,
            (int) $deal->pipeline_id,
            (int) $deal->stage_id,
        );

        $eventTs = $deal->created_at ?? now();

        foreach ($automations as $automation) {
            // Fault isolation: one bad rule must not abort the others (the rest
            // of the inbound listener chain) — claimAndQueue already swallows
            // handler faults into a `failed` run, but a claim-time DB hiccup is
            // caught here per-automation.
            try {
                $this->dispatcher->claimAndQueue($automation, $deal, $eventTs);
            } catch (Throwable $e) {
                Log::warning('RunOnCreateAutomations: failed to enqueue automation', [
                    'automation_id' => $automation->id,
                    'deal_id' => $deal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
