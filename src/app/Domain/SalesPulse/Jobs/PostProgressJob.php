<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\ProgressLine;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use App\Domain\SalesPulse\Renderers\ProgressRenderer;
use App\Domain\SalesPulse\Services\ProgressService;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use App\Domain\SalesPulse\Services\SkipService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PostProgressJob — the scheduled /progress post (spec §3): 13:00 "полдень" and
 * 16:00 "вечер". The named label is the only difference between the two ticks.
 *
 * Reuses the SAME ProgressService / ProgressRenderer as the /progress command, so
 * the scheduled post is byte-for-byte the manual one (vacation / skip / no-plan /
 * live variants), just with a named label instead of the wall-clock HH:MM. One
 * message per team; weekend / team-skip guarded.
 *
 * Read-only — recomputes live counters from current state, never writes, so
 * re-running is harmless.
 */
class PostProgressJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        private readonly string $label,
    ) {}

    public function handle(
        RosterResolver $roster,
        ProgressService $progress,
        SkipService $skips,
        ProgressRenderer $renderer,
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

            $lines = $this->buildLines($team, $today, $progress, $skips);
            $message = $renderer->render($team->name, $today, $this->label, $lines);
            $notifier->sendToChat($team->chatId, $message);
        }
    }

    /**
     * @return list<ProgressLine>
     */
    private function buildLines(Team $team, CarbonImmutable $date, ProgressService $progress, SkipService $skips): array
    {
        $lines = [];

        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            $vacationUntil = $skips->vacationUntil($date, $user);

            $lines[] = $progress->lineFor(
                manager: $user,
                date: $date,
                nameLink: $this->nameLink($entry),
                teamChatId: $team->chatId,
                vacationUntil: $vacationUntil?->format('d.m'),
            );
        }

        return $lines;
    }

    private function nameLink(TeamManager $entry): string
    {
        return $entry->name;
    }
}
