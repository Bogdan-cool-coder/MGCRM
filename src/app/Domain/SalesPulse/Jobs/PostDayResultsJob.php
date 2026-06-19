<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Services\DayResultsService;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\MetricsService;
use App\Domain\SalesPulse\Services\NotesService;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PostDayResultsJob — the scheduled /dayresults post (spec §3):
 *   - 08:30 Tue–Fri  → forToday = false → analyse the PREVIOUS day.
 *   - 20:00 Fri      → forToday = true  → analyse Friday itself (so it does not
 *                       land on Saturday's 08:30 slot, which never fires).
 *
 * The analysed date must itself be a WORKING day (spec §3 — guard выходной); a
 * non-working target date short-circuits. Per roster manager it loads the stored
 * FACT (else a fresh collectDay), the morning PLAN and the notes-today set,
 * computes `missed`, and posts the DayResultsService breakdown — one message per
 * manager who had activity (no empty card).
 *
 * Reuses the exact same services as the /dayresults command, so the scheduled and
 * manual outputs are identical. Read-only.
 */
class PostDayResultsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly bool $forToday,
    ) {}

    public function handle(
        RosterResolver $roster,
        SnapshotRepository $repository,
        DaySnapshotService $snapshots,
        NotesService $notes,
        MetricsService $metrics,
        DayResultsService $dayResults,
        SalesPulseNotifier $notifier,
    ): void {
        $today = $roster->today();
        $on = $this->forToday ? $today : $today->subDay();

        // The ANALYSED day must be a working day (spec §3 — guard выходной).
        if (! $roster->isWorkingDay($on)) {
            return;
        }

        foreach ($roster->teams() as $team) {
            if ($roster->isTeamSkipped($team, $on)) {
                continue;
            }

            $this->postTeam($team, $on, $roster, $repository, $snapshots, $notes, $metrics, $dayResults, $notifier);
        }
    }

    private function postTeam(
        Team $team,
        CarbonImmutable $on,
        RosterResolver $roster,
        SnapshotRepository $repository,
        DaySnapshotService $snapshots,
        NotesService $notes,
        MetricsService $metrics,
        DayResultsService $dayResults,
        SalesPulseNotifier $notifier,
    ): void {
        $onDate = $on->toDateString();

        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            if ($roster->isManagerSkipped($team, $user, $on)) {
                continue;
            }

            $evening = $repository->load((int) $user->id, $onDate, SnapKind::Fact)
                ?? $snapshots->collectDay($user, $on, $team->pipelineIds);

            // Nothing happened that day → skip the manager (no empty card).
            if ($evening->plan === [] && $evening->fact === []) {
                continue;
            }

            $morningPlan = $repository->load((int) $user->id, $onDate, SnapKind::Plan);
            $notesToday = $notes->dealIdsWithNoteToday($user, $on);
            $missed = $metrics->compute($morningPlan, $evening, $notesToday)->missed;

            $message = $dayResults->renderForManager(
                managerName: (string) $user->full_name,
                morningPlan: $morningPlan,
                eveningSnap: $evening,
                dealIdsWithNotesToday: $notesToday,
                missed: $missed,
            );

            $notifier->sendToChat($team->chatId, $message);
        }
    }
}
