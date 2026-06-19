<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Data\TeamManager;
use Carbon\CarbonImmutable;

/**
 * RosterResolver — the scheduler's shared roster + guard helper (Slice 4).
 *
 * The scheduled jobs (RemindPlanJob, AutoCapturePlanJob, PostProgressJob, …) all
 * need the same three things: the configured teams, the User behind each roster
 * entry, and the weekend / team-skip / manager-skip gates (spec §3). Centralising
 * them here keeps every job thin and makes the guard logic unit-testable without a
 * Nutgram or a queue.
 */
class RosterResolver
{
    public function __construct(
        private readonly SkipService $skips,
    ) {}

    /**
     * Every configured team (decoded from config('salespulse.teams')).
     *
     * @return list<Team>
     */
    public function teams(): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) config('salespulse.teams', []);

        return array_values(array_map(
            static fn (array $row): Team => Team::fromArray($row),
            array_filter($raw, 'is_array'),
        ));
    }

    /**
     * The current Asia/Dubai date at start-of-day (the scheduler's "today").
     */
    public function today(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now($this->timezone());

        return $now->setTimezone($this->timezone())->startOfDay();
    }

    /**
     * Is the date a working day (Mon–Fri)? The scheduler early-returns on weekends
     * (spec §3).
     */
    public function isWorkingDay(CarbonImmutable $date): bool
    {
        return ! $date->isWeekend();
    }

    /**
     * Is the WHOLE team skipped on a date (a team-wide skip row, spec §3)? A skipped
     * team's jobs do nothing.
     */
    public function isTeamSkipped(Team $team, CarbonImmutable $date): bool
    {
        return $this->skips->isTeamSkipped($date, $team->chatId);
    }

    /**
     * Is a specific manager skipped (personal or via the team skip, spec §3)?
     */
    public function isManagerSkipped(Team $team, User $manager, CarbonImmutable $date): bool
    {
        return $this->skips->isManagerSkipped($date, $manager, $team->chatId);
    }

    /**
     * Resolve the roster to live, NON-skipped Users for a date (spec §3 — jobs act
     * only on managers who are not on skip/vacation). Order follows the roster.
     *
     * @return list<array{entry: TeamManager, user: User}>
     */
    public function activeManagers(Team $team, CarbonImmutable $date): array
    {
        $out = [];
        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user === null) {
                continue;
            }
            if ($this->isManagerSkipped($team, $user, $date)) {
                continue;
            }
            $out[] = ['entry' => $entry, 'user' => $user];
        }

        return $out;
    }

    /**
     * Resolve the full roster to Users (skip filter NOT applied) — for jobs that
     * render every manager (e.g. /progress shows a skipped manager as "⏸ скип").
     *
     * @return list<array{entry: TeamManager, user: User}>
     */
    public function allManagers(Team $team): array
    {
        $out = [];
        foreach ($team->managers as $entry) {
            $user = User::query()->find($entry->userId);
            if ($user !== null) {
                $out[] = ['entry' => $entry, 'user' => $user];
            }
        }

        return $out;
    }

    /**
     * The @-mention for a roster entry: "@{tg}" when a username exists, else the
     * display name (spec §3 reminder text).
     */
    public function mention(TeamManager $entry): string
    {
        return $entry->tg !== null && $entry->tg !== ''
            ? '@'.$entry->tg
            : $entry->name;
    }

    public function timezone(): string
    {
        return (string) config('salespulse.timezone', 'Asia/Dubai');
    }
}
