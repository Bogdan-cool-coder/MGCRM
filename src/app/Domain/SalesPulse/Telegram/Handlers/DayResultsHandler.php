<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram\Handlers;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Services\DayResultsService;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\MetricsService;
use App\Domain\SalesPulse\Services\NotesService;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use App\Domain\SalesPulse\Services\TeamResolver;
use App\Domain\SalesPulse\Telegram\CommandContextResolver;
use Carbon\CarbonImmutable;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * DayResultsHandler — /dayresults [дата] (admin, spec §5.1).
 *
 * For each roster manager: load the stored evening FACT (fall back to a fresh
 * collectDay), the morning PLAN, the notes-today set; compute `missed` via
 * MetricsService; then DayResultsService renders the LLM (or offline) breakdown.
 * One message per manager with data.
 */
class DayResultsHandler
{
    use AdminGate;

    public function __construct(
        private readonly CommandContextResolver $resolver,
        private readonly TeamResolver $teams,
        private readonly SnapshotRepository $repository,
        private readonly DaySnapshotService $snapshots,
        private readonly NotesService $notes,
        private readonly MetricsService $metrics,
        private readonly DayResultsService $dayResults,
    ) {}

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $ctx = $this->resolver->resolve($bot);
        if (! $this->passesAdminGate($bot, $ctx)) {
            return;
        }

        [$date] = $this->teams->parseArgs($ctx->args);

        foreach ($ctx->team->managers as $entry) {
            $user = $this->teams->userFor($entry);
            if ($user === null) {
                continue;
            }

            $message = $this->renderManager($user, $date, $ctx->team->pipelineIds);
            if ($message !== null) {
                $bot->sendMessage(text: $message, parse_mode: ParseMode::HTML);
            }
        }
    }

    /**
     * @param  list<int>  $pipelineIds
     */
    private function renderManager(User $user, CarbonImmutable $date, array $pipelineIds): ?string
    {
        $onDate = $date->toDateString();

        $evening = $this->repository->load((int) $user->id, $onDate, SnapKind::Fact)
            ?? $this->snapshots->collectDay($user, $date, $pipelineIds);

        // Nothing happened today → skip the manager (no empty card).
        if ($evening->plan === [] && $evening->fact === []) {
            return null;
        }

        $morningPlan = $this->repository->load((int) $user->id, $onDate, SnapKind::Plan);
        $notesToday = $this->notes->dealIdsWithNoteToday($user, $date);
        $missed = $this->metrics->compute($morningPlan, $evening, $notesToday)->missed;

        return $this->dayResults->renderForManager(
            managerName: (string) $user->full_name,
            morningPlan: $morningPlan,
            eveningSnap: $evening,
            dealIdsWithNotesToday: $notesToday,
            missed: $missed,
        );
    }
}
