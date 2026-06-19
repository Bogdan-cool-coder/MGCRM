<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AutoCaptureFactJob — the 19:45 auto-fact (spec §3).
 *
 * For each non-skipped manager who still has NO fact today, collect their day and
 * save it as the AUTO evening FACT — SILENTLY (no chat post, unlike auto-plan).
 *
 * Idempotent: only managers without a fact_at are captured, so a manager who ran
 * /finishday earlier keeps their manual fact and is left alone (no overwrite of a
 * manual fact with an auto one).
 */
class AutoCaptureFactJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(
        RosterResolver $roster,
        DaySnapshotService $snapshots,
        SnapshotRepository $repository,
    ): void {
        $today = $roster->today();
        if (! $roster->isWorkingDay($today)) {
            return; // weekend guard (spec §3).
        }

        foreach ($roster->teams() as $team) {
            if ($roster->isTeamSkipped($team, $today)) {
                continue;
            }

            $this->captureTeam($team, $today, $roster, $snapshots, $repository);
        }
    }

    private function captureTeam(
        Team $team,
        CarbonImmutable $today,
        RosterResolver $roster,
        DaySnapshotService $snapshots,
        SnapshotRepository $repository,
    ): void {
        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            if ($roster->isManagerSkipped($team, $user, $today)) {
                continue;
            }

            if ($this->hasFact($user, $today)) {
                continue; // already fixed (manual or a prior auto run).
            }

            $snapshot = $snapshots->collectDay($user, $today, $team->pipelineIds);
            $repository->saveFact($snapshot, SnapSource::Auto);
        }
    }

    private function hasFact(User $user, CarbonImmutable $today): bool
    {
        return PulseDailyStatus::query()
            ->where('manager_id', $user->id)
            ->whereDate('on_date', $today->toDateString())
            ->whereNotNull('fact_at')
            ->exists();
    }
}
