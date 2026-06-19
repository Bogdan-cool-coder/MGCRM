<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SkipKind;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * SkipService — port of the AMO bot's skips.py (spec §3). Owns the pulse_skip_days
 * table: one-day skips, multi-day vacations, and the detection helpers the
 * scheduler (Slice 4) and /progress (Slice 1) consume.
 *
 *   - skipDay / unskipDay: a single day off, personal (manager_id set) or
 *     team-wide (manager_id null + team_chat_id). Idempotent — a duplicate skip is
 *     a no-op ("already skipped").
 *   - vacation / unvacation: a span of 2+ consecutive WORKING days (Mon–Fri).
 *     Stored as one row per covered working day, all sharing the same
 *     vacation_until so /progress renders "🌴 отпуск до DD.MM".
 *   - isTeamSkipped / isManagerSkipped: scheduler gates (spec §3).
 *   - isReturningFromVacation: the manager was on vacation on the previous working
 *     day but is not today — the first day back (spec §3 welcome-back line).
 *
 * All writes are idempotent via a pre-check so a re-run never duplicates a row.
 */
class SkipService
{
    /**
     * Mark one day off. team_chat_id present + manager null = team-wide; manager
     * present = personal. Returns true when a row was created, false when it
     * already existed (idempotent — spec §8 "уже пропущен").
     */
    public function skipDay(
        CarbonImmutable $date,
        ?string $teamChatId,
        ?User $manager,
        string $createdBy,
    ): bool {
        $onDate = $date->toDateString();

        if ($this->skipExists($onDate, $teamChatId, $manager)) {
            return false;
        }

        PulseSkipDay::create([
            'on_date' => $onDate,
            'kind' => SkipKind::Skip,
            'vacation_until' => null,
            'team_chat_id' => $manager === null ? $teamChatId : null,
            'manager_id' => $manager?->id,
            'created_by' => $createdBy,
        ]);

        return true;
    }

    /**
     * Remove a one-day skip. Returns true when a row was deleted.
     */
    public function unskipDay(CarbonImmutable $date, ?string $teamChatId, ?User $manager): bool
    {
        $deleted = $this->skipQuery($date->toDateString(), $teamChatId, $manager)
            ->where('kind', SkipKind::Skip)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Mark a manager's vacation: every WORKING day in [from, until] inclusive gets
     * one personal row carrying vacation_until = until (spec §3 — 2+ consecutive
     * working days). Returns the number of working days covered (0 if the span has
     * no working days). Idempotent per day.
     */
    public function vacation(
        CarbonImmutable $from,
        CarbonImmutable $until,
        User $manager,
        string $createdBy,
    ): int {
        $vacationUntil = $until->toDateString();
        $covered = 0;

        foreach ($this->workingDaysInSpan($from, $until) as $day) {
            $onDate = $day->toDateString();

            $exists = PulseSkipDay::query()
                ->whereDate('on_date', $onDate)
                ->where('manager_id', $manager->id)
                ->exists();

            if ($exists) {
                $covered++;

                continue;
            }

            PulseSkipDay::create([
                'on_date' => $onDate,
                'kind' => SkipKind::Vacation,
                'vacation_until' => $vacationUntil,
                'team_chat_id' => null,
                'manager_id' => $manager->id,
                'created_by' => $createdBy,
            ]);

            $covered++;
        }

        return $covered;
    }

    /**
     * Clear a manager's vacation rows from $from onward (inclusive). Returns the
     * number of rows removed.
     */
    public function unvacation(CarbonImmutable $from, User $manager): int
    {
        return (int) PulseSkipDay::query()
            ->where('manager_id', $manager->id)
            ->where('kind', SkipKind::Vacation)
            ->whereDate('on_date', '>=', $from->toDateString())
            ->delete();
    }

    /**
     * Is the WHOLE team skipped on a date (a team-wide skip row, spec §3)?
     */
    public function isTeamSkipped(CarbonImmutable $date, string $teamChatId): bool
    {
        return PulseSkipDay::query()
            ->whereDate('on_date', $date->toDateString())
            ->whereNull('manager_id')
            ->where('team_chat_id', $teamChatId)
            ->exists();
    }

    /**
     * Is a manager skipped on a date — personally OR because the team is skipped
     * (spec §3). A vacation row counts as a skip.
     */
    public function isManagerSkipped(CarbonImmutable $date, User $manager, ?string $teamChatId = null): bool
    {
        $onDate = $date->toDateString();

        $personal = PulseSkipDay::query()
            ->whereDate('on_date', $onDate)
            ->where('manager_id', $manager->id)
            ->exists();

        if ($personal) {
            return true;
        }

        if ($teamChatId !== null && $teamChatId !== '') {
            return $this->isTeamSkipped($date, $teamChatId);
        }

        return false;
    }

    /**
     * The vacation end date for a manager active on $date, or null when they are
     * not on vacation that day. Feeds /progress's "🌴 отпуск до DD.MM" label.
     */
    public function vacationUntil(CarbonImmutable $date, User $manager): ?CarbonImmutable
    {
        /** @var PulseSkipDay|null $row */
        $row = PulseSkipDay::query()
            ->whereDate('on_date', $date->toDateString())
            ->where('manager_id', $manager->id)
            ->where('kind', SkipKind::Vacation)
            ->first();

        if ($row === null || $row->vacation_until === null) {
            return null;
        }

        return CarbonImmutable::parse($row->vacation_until);
    }

    /**
     * Is the manager returning from vacation today (spec §3)? True when they were
     * on vacation on the PREVIOUS working day but are NOT on vacation today.
     */
    public function isReturningFromVacation(CarbonImmutable $date, User $manager): bool
    {
        // On vacation today → not returning.
        if ($this->onVacation($date, $manager)) {
            return false;
        }

        $prevWorkingDay = $this->previousWorkingDay($date);

        return $this->onVacation($prevWorkingDay, $manager);
    }

    private function onVacation(CarbonImmutable $date, User $manager): bool
    {
        return PulseSkipDay::query()
            ->whereDate('on_date', $date->toDateString())
            ->where('manager_id', $manager->id)
            ->where('kind', SkipKind::Vacation)
            ->exists();
    }

    private function skipExists(string $onDate, ?string $teamChatId, ?User $manager): bool
    {
        return $this->skipQuery($onDate, $teamChatId, $manager)->exists();
    }

    /**
     * @return Builder<PulseSkipDay>
     */
    private function skipQuery(string $onDate, ?string $teamChatId, ?User $manager): Builder
    {
        $query = PulseSkipDay::query()->whereDate('on_date', $onDate);

        if ($manager !== null) {
            return $query->where('manager_id', $manager->id);
        }

        return $query->whereNull('manager_id')->where('team_chat_id', $teamChatId);
    }

    /**
     * @return list<CarbonImmutable> Working days (Mon–Fri) in [from, until] inclusive.
     */
    private function workingDaysInSpan(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $days = [];
        $cursor = $from->startOfDay();
        $end = $until->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor;
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private function previousWorkingDay(CarbonImmutable $date): CarbonImmutable
    {
        $cursor = $date->subDay()->startOfDay();
        while ($cursor->isWeekend()) {
            $cursor = $cursor->subDay();
        }

        return $cursor;
    }
}
