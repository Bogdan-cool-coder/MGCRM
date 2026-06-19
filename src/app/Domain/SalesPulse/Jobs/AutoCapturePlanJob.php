<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Services\DaySnapshotService;
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
 * AutoCapturePlanJob — the 10:15 auto-plan (spec §3).
 *
 * For each non-skipped manager who still has NO plan today, collect their day and
 * fix it as the AUTO morning PLAN (write-once), then post a one-line confirmation
 * to the team chat: "📋 [auto] План для {name} зафиксирован системой."
 *
 * Idempotent on two levels: it only acts on managers without a plan_at, and
 * SnapshotRepository::savePlan is itself write-once (a PLAN already present is left
 * untouched). So a re-run after a manager ran /startday in the interim does
 * nothing for them.
 */
class AutoCapturePlanJob implements ShouldQueue
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
        SalesPulseNotifier $notifier,
    ): void {
        $today = $roster->today();
        if (! $roster->isWorkingDay($today)) {
            return; // weekend guard (spec §3).
        }

        foreach ($roster->teams() as $team) {
            if ($roster->isTeamSkipped($team, $today)) {
                continue;
            }

            $this->captureTeam($team, $today, $roster, $snapshots, $repository, $notifier);
        }
    }

    private function captureTeam(
        Team $team,
        CarbonImmutable $today,
        RosterResolver $roster,
        DaySnapshotService $snapshots,
        SnapshotRepository $repository,
        SalesPulseNotifier $notifier,
    ): void {
        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            if ($roster->isManagerSkipped($team, $user, $today)) {
                continue;
            }

            if ($this->hasPlan($user, $today)) {
                continue; // already fixed (manual or a prior auto run).
            }

            $snapshot = $snapshots->collectDay($user, $today, $team->pipelineIds);
            $repository->savePlan($snapshot, SnapSource::Auto);

            $notifier->sendToChat(
                $team->chatId,
                "📋 [auto] План для {$entry->name} зафиксирован системой.",
            );
        }
    }

    private function hasPlan(User $user, CarbonImmutable $today): bool
    {
        return PulseDailyStatus::query()
            ->where('manager_id', $user->id)
            ->whereDate('on_date', $today->toDateString())
            ->whereNotNull('plan_at')
            ->exists();
    }
}
