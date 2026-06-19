<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
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
 * RemindPlanJob — the 09:30 / 10:00 plan reminder (spec §3).
 *
 * For each team (not weekend, not team-skipped) it pings the managers who have NOT
 * fixed a plan yet (no plan_at on today's pulse_daily_status) and are not on a
 * skip, plus a welcome-back line for anyone returning from vacation today. When
 * there is no one to remind AND no one returning, it stays silent (no empty post).
 *
 * Idempotent: a second run at 10:00 simply re-evaluates "who still has no plan",
 * so a manager who fixed their plan in between is no longer pinged. Posts through
 * SalesPulseNotifier (outbound, no polling — runs in scheduler/queue, never 409).
 *
 * PHP 8.5 Queueable gotcha (plan §3): the queue name is NOT a class property
 * ($queue conflicts with the Queueable trait) — the default queue is used.
 */
class RemindPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(
        RosterResolver $roster,
        SkipService $skips,
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

            $message = $this->buildMessage($team, $today, $roster, $skips);
            if ($message !== null) {
                $notifier->sendToChat($team->chatId, $message);
            }
        }
    }

    /**
     * Build the reminder for one team, or null when no one needs reminding and no
     * one is returning (spec §3 silent skip).
     */
    private function buildMessage(Team $team, CarbonImmutable $today, RosterResolver $roster, SkipService $skips): ?string
    {
        $mentions = [];
        $returning = [];

        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            if ($roster->isManagerSkipped($team, $user, $today)) {
                continue;
            }

            if ($skips->isReturningFromVacation($today, $user)) {
                $returning[] = $this->welcomeBack($entry);
            }

            if (! $this->hasPlan($user, $today)) {
                $mentions[] = $roster->mention($entry);
            }
        }

        if ($mentions === [] && $returning === []) {
            return null;
        }

        $lines = [];
        if ($mentions !== []) {
            $lines[] = implode(' ', $mentions).' — ожидаю план рабочего дня (/startday).';
        }
        foreach ($returning as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * "🎉 @{tg} ({name}) вернулся из отпуска, с возвращением!" (spec §3).
     */
    private function welcomeBack(TeamManager $entry): string
    {
        $handle = $entry->tg !== null && $entry->tg !== '' ? '@'.$entry->tg : $entry->name;

        return "🎉 {$handle} ({$entry->name}) вернулся из отпуска, с возвращением!";
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
