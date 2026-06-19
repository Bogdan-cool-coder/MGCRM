<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Jobs;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Services\RosterResolver;
use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RemindFactJob — the 19:00 / 19:30 fact reminder (spec §3).
 *
 * For each team (not weekend, not team-skipped) it pings the managers who have NOT
 * fixed a fact yet (no fact_at on today's pulse_daily_status) and are not skipped.
 * Unlike the plan reminder there is no welcome-back line (those land in the morning
 * plan reminder). Silent when no one needs reminding.
 *
 * Idempotent: the 19:30 re-run re-evaluates "who still has no fact". Outbound via
 * SalesPulseNotifier (no polling).
 */
class RemindFactJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(
        RosterResolver $roster,
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

            $message = $this->buildMessage($team, $today, $roster);
            if ($message !== null) {
                $notifier->sendToChat($team->chatId, $message);
            }
        }
    }

    private function buildMessage(Team $team, CarbonImmutable $today, RosterResolver $roster): ?string
    {
        $mentions = [];

        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }

            if ($roster->isManagerSkipped($team, $user, $today)) {
                continue;
            }

            if (! $this->hasFact($user, $today)) {
                $mentions[] = $roster->mention($entry);
            }
        }

        if ($mentions === []) {
            return null;
        }

        return implode(' ', $mentions).' — ожидаю итоги рабочего дня (/finishday).';
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
