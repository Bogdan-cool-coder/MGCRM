<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SkipKind;
use App\Domain\SalesPulse\Models\PulseSkipDay;
use App\Domain\SalesPulse\Services\SkipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SkipService (Slice 3, port of skips.py / spec §3): personal & team skips,
 * multi-day vacation (2+ working days), and the scheduler detection helpers.
 */
class SkipServiceTest extends TestCase
{
    use RefreshDatabase;

    private SkipService $skips;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skips = new SkipService;
    }

    private function date(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso);
    }

    public function test_personal_skip_is_idempotent(): void
    {
        $manager = User::factory()->create();
        $date = $this->date('2026-06-18'); // Thursday

        $this->assertTrue($this->skips->skipDay($date, '-1001', $manager, 'admin'));
        $this->assertFalse($this->skips->skipDay($date, '-1001', $manager, 'admin')); // already skipped

        $this->assertSame(1, PulseSkipDay::query()->where('manager_id', $manager->id)->count());
        $this->assertTrue($this->skips->isManagerSkipped($date, $manager, '-1001'));
    }

    public function test_team_skip_marks_every_manager(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $date = $this->date('2026-06-18');

        $this->assertTrue($this->skips->skipDay($date, '-1001', null, 'admin'));

        $this->assertTrue($this->skips->isTeamSkipped($date, '-1001'));
        $this->assertTrue($this->skips->isManagerSkipped($date, $a, '-1001'));
        $this->assertTrue($this->skips->isManagerSkipped($date, $b, '-1001'));
        // No personal row exists — the skip is team-wide.
        $this->assertSame(0, PulseSkipDay::query()->whereNotNull('manager_id')->count());
    }

    public function test_unskip_removes_the_skip(): void
    {
        $manager = User::factory()->create();
        $date = $this->date('2026-06-18');

        $this->skips->skipDay($date, '-1001', $manager, 'admin');
        $this->assertTrue($this->skips->unskipDay($date, '-1001', $manager));
        $this->assertFalse($this->skips->unskipDay($date, '-1001', $manager)); // nothing left
        $this->assertFalse($this->skips->isManagerSkipped($date, $manager, '-1001'));
    }

    public function test_vacation_covers_only_working_days(): void
    {
        $manager = User::factory()->create();

        // Thu 18 → Tue 23 June 2026. Working days: Thu 18, Fri 19, Mon 22, Tue 23 = 4.
        // Sat 20 + Sun 21 are skipped.
        $days = $this->skips->vacation($this->date('2026-06-18'), $this->date('2026-06-23'), $manager, 'admin');

        $this->assertSame(4, $days);
        $this->assertSame(4, PulseSkipDay::query()
            ->where('manager_id', $manager->id)
            ->where('kind', SkipKind::Vacation->value)
            ->count());

        // The weekend got no rows.
        $this->assertFalse(
            PulseSkipDay::query()->whereDate('on_date', '2026-06-20')->exists(),
        );

        // vacation_until is stamped on every row.
        $this->assertTrue($this->skips->isManagerSkipped($this->date('2026-06-22'), $manager));
        $until = $this->skips->vacationUntil($this->date('2026-06-22'), $manager);
        $this->assertNotNull($until);
        $this->assertSame('2026-06-23', $until->toDateString());
    }

    public function test_returning_from_vacation_on_first_day_back(): void
    {
        $manager = User::factory()->create();

        // Vacation Thu 18 → Fri 19 June (Mon 22 is the first day back).
        $this->skips->vacation($this->date('2026-06-18'), $this->date('2026-06-19'), $manager, 'admin');

        // Mon 22: previous WORKING day (Fri 19) was vacation, today is not → returning.
        $this->assertTrue($this->skips->isReturningFromVacation($this->date('2026-06-22'), $manager));

        // Fri 19 itself: still on vacation → NOT returning.
        $this->assertFalse($this->skips->isReturningFromVacation($this->date('2026-06-19'), $manager));

        // Tue 23: previous working day (Mon 22) was not vacation → NOT returning.
        $this->assertFalse($this->skips->isReturningFromVacation($this->date('2026-06-23'), $manager));
    }

    public function test_unvacation_clears_from_date_onward(): void
    {
        $manager = User::factory()->create();
        $this->skips->vacation($this->date('2026-06-18'), $this->date('2026-06-23'), $manager, 'admin');

        $removed = $this->skips->unvacation($this->date('2026-06-22'), $manager);

        // Mon 22 + Tue 23 removed; Thu 18 + Fri 19 remain.
        $this->assertSame(2, $removed);
        $this->assertSame(2, PulseSkipDay::query()->where('manager_id', $manager->id)->count());
    }

    public function test_vacation_upgrades_a_pre_existing_skip_day_to_vacation(): void
    {
        $manager = User::factory()->create();
        $from = $this->date('2026-06-18'); // Thursday
        $until = $this->date('2026-06-19'); // Friday

        // A plain one-day skip already sits on the first day of the span.
        $this->assertTrue($this->skips->skipDay($from, '-1001', $manager, 'admin'));
        $this->assertSame(SkipKind::Skip, PulseSkipDay::query()
            ->whereDate('on_date', '2026-06-18')->where('manager_id', $manager->id)->value('kind'));

        $days = $this->skips->vacation($from, $until, $manager, 'admin');

        // Both working days are covered (the pre-existing one is upgraded, not skipped).
        $this->assertSame(2, $days);

        // The previously-plain skip is now a vacation row carrying vacation_until.
        $upgraded = PulseSkipDay::query()
            ->whereDate('on_date', '2026-06-18')->where('manager_id', $manager->id)->firstOrFail();
        $this->assertSame(SkipKind::Vacation, $upgraded->kind);
        $this->assertNotNull($upgraded->vacation_until);
        $this->assertSame('2026-06-19', $upgraded->vacation_until->toDateString());

        // No duplicate row was created for the upgraded day.
        $this->assertSame(2, PulseSkipDay::query()->where('manager_id', $manager->id)->count());

        // /progress now labels it as on-vacation, and /unvacation can clear it.
        $this->assertNotNull($this->skips->vacationUntil($from, $manager));
        $this->assertSame(2, $this->skips->unvacation($from, $manager));
        $this->assertSame(0, PulseSkipDay::query()->where('manager_id', $manager->id)->count());
    }
}
