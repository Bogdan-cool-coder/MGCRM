<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Renderers\FactRenderer;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\MetricsService;
use App\Domain\SalesPulse\Services\NotesService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use App\Domain\SalesPulse\Telegram\SalesPulseMessages;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * FinishdayHandler — /finishday [менеджер] [дата] (spec §7).
 *
 * Flow: resolve target manager → "⌛ Тяну факт..." → fresh evening collectDay →
 * load the morning PLAN → MetricsService → saveFact(MANUAL, upsert) → FactRenderer.
 */
class FinishdayHandler
{
    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly DaySnapshotService $snapshots,
        private readonly SnapshotRepository $repository,
        private readonly NotesService $notes,
        private readonly MetricsService $metrics,
        private readonly FactRenderer $renderer,
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

        $bot->sendMessage(SalesPulseMessages::pullingFact((string) $manager->full_name, $date));

        $evening = $this->snapshots->collectDay($manager, $date, $ctx->team->pipelineIds);
        $this->repository->saveFact($evening, SnapSource::Manual);

        $morningPlan = $this->repository->load((int) $manager->id, $date->toDateString(), SnapKind::Plan);

        $notesToday = $this->notes->dealIdsWithNoteToday($manager, $date);

        $computed = $this->metrics->compute($morningPlan, $evening, $notesToday);

        $bot->sendMessage(SalesPulseMessages::FACT_FIXED);
        $bot->sendMessage(
            text: $this->renderer->render($morningPlan, $evening, $notesToday, $computed, $date),
            parse_mode: ParseMode::HTML,
        );
    }
}
