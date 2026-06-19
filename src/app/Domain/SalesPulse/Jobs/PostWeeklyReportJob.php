<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use App\Domain\SalesPulse\Services\WeeklyAggregationService;
use App\Domain\SalesPulse\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PostWeeklyReportJob — the Monday 09:00 weekly report (spec §3).
 *
 * Aggregates the PREVIOUS working week (the Monday before this week's Monday) and
 * posts the two WeeklyReportService messages (report + narrative) per team. Reuses
 * the exact services behind the /weeklyreport command.
 *
 * Read-only aggregation; re-running re-renders the same week, so it is harmless.
 * Team-skip guarded (a fully-skipped team gets no report).
 */
class PostWeeklyReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(
        RosterResolver $roster,
        WeeklyAggregationService $aggregation,
        WeeklyReportService $report,
        SalesPulseNotifier $notifier,
    ): void {
        $today = $roster->today();

        // Previous working week = the Monday a week before this week's Monday.
        $previousMonday = $today
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->subWeek();

        foreach ($roster->teams() as $team) {
            if ($roster->isTeamSkipped($team, $today)) {
                continue;
            }

            $this->postTeam($team, $previousMonday, $aggregation, $report, $notifier);
        }
    }

    private function postTeam(
        Team $team,
        CarbonImmutable $previousMonday,
        WeeklyAggregationService $aggregation,
        WeeklyReportService $report,
        SalesPulseNotifier $notifier,
    ): void {
        $managers = $this->loadManagers($team);
        if ($managers === []) {
            return;
        }

        $data = $aggregation->aggregate(
            teamName: $team->name,
            managers: $managers,
            weekStart: $previousMonday,
            pipelineIds: $team->pipelineIds,
            teamChatId: $team->chatId,
        );

        [$reportMessage, $narrativeMessage] = $report->render($data);

        $notifier->sendToChat($team->chatId, $reportMessage);
        $notifier->sendToChat($team->chatId, $narrativeMessage);
    }

    /**
     * @return list<User>
     */
    private function loadManagers(Team $team): array
    {
        $managers = [];
        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user !== null) {
                $managers[] = $user;
            }
        }

        return $managers;
    }
}
