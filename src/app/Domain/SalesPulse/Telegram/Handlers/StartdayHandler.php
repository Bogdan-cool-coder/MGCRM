<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseStageResolver;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Renderers\PlanRenderer;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;

/**
 * StartdayHandler — /startday [менеджер] [дата] (spec §7).
 *
 * Flow: resolve the target manager (caller for themselves; an admin may target
 * another via a slug, spec §8) → "⌛ Тяну план..." → collectDay → savePlan(MANUAL,
 * write-once) → render the plan. A weekend date short-circuits with the §7 warning.
 *
 * Available to any roster manager (for themselves); an admin acting for another
 * manager is gated inside TeamResolver::resolveTargetUser.
 */
class StartdayHandler
{
    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly DaySnapshotService $snapshots,
        private readonly SnapshotRepository $repository,
        private readonly PlanRenderer $renderer,
    ) {}

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $ctx->hasTeam()) {
            return;
        }

        [$date] = $this->teams->parseArgs($ctx->args);
        $manager = $this->teams->resolveTargetUser($ctx->team, $ctx->callerTg, $ctx->args);

        if ($manager === null) {
            $bot->sendMessage(SalesPulseMessages::NOT_A_MANAGER);

            return;
        }

        // Weekend → no plan (spec §7).
        if ($date->isWeekend()) {
            $bot->sendMessage(SalesPulseMessages::planWeekend($date));

            return;
        }

        $bot->sendMessage(SalesPulseMessages::pullingPlan((string) $manager->full_name, $date));

        $snapshot = $this->snapshots->collectDay($manager, $date, $ctx->team->pipelineIds);
        $this->repository->savePlan($snapshot, SnapSource::Manual);

        $stages = $this->resolveStages($snapshot);

        $bot->sendMessage(SalesPulseMessages::PLAN_FIXED);
        $bot->sendMessage($this->renderer->render($snapshot, $stages));
    }

    /**
     * Build the stage resolver from the stage ids the plan rows reference.
     */
    private function resolveStages(DaySnapshot $snapshot): PulseStageResolver
    {
        $stageIds = array_values(array_unique(array_filter(array_map(
            static fn (PulseTaskRow $r): ?int => $r->dealStageId,
            $snapshot->plan,
        ))));

        if ($stageIds === []) {
            return new PulseStageResolver;
        }

        return PulseStageResolver::fromStages(
            PipelineStage::query()->whereIn('id', $stageIds)->get(),
        );
    }
}
