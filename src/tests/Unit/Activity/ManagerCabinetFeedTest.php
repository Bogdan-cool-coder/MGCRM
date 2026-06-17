<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Iam\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for the two ActivityService contracts consumed by the S1.8 manager
 * cabinet (plan §В1 / §Л): countFtmForUser() and feedForUser().
 *
 * Both are read-only, user-scoped aggregations over Activity. Visibility-scope
 * on ?user_id= is resolved by the caller (ManagerKpiService::resolveTargetUser),
 * so these methods only enforce responsible_id == userId and the period window.
 *
 * The five FTM conditions (plan §Б2) are single-sourced in the service, so the
 * count, the ftm_only feed filter and the per-item flag can never disagree.
 */
class ManagerCabinetFeedTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed one-month window so period boundaries are deterministic.
        $this->from = CarbonImmutable::parse('2026-05-01 00:00:00');
        $this->to = CarbonImmutable::parse('2026-05-31 23:59:59');
    }

    private function service(): ActivityService
    {
        return app(ActivityService::class);
    }

    private function manager(): User
    {
        return User::factory()->create();
    }

    /**
     * A meeting that satisfies ALL five FTM conditions for the given user, with
     * a created_at inside the test window (mid-May).
     */
    private function countedFtm(User $user, ?CarbonImmutable $createdAt = null): Activity
    {
        return Activity::factory()
            ->meeting()
            ->responsibleOf($user)
            ->create([
                'is_first_time_meeting' => true,
                'ftm_decision_maker_attended' => true,
                'ftm_presentation_shown' => true,
                'ftm_report_url' => 'https://reports.example.com/ftm/1',
                'created_at' => $createdAt ?? CarbonImmutable::parse('2026-05-15 10:00:00'),
            ]);
    }

    // ---- countFtmForUser ----

    public function test_counts_meeting_with_all_five_conditions(): void
    {
        $user = $this->manager();
        $this->countedFtm($user);

        $this->assertSame(1, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_not_counted_when_kind_is_not_meeting(): void
    {
        $user = $this->manager();
        // Same FTM flags but kind = call → must NOT count (condition 1).
        Activity::factory()->call()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://reports.example.com/ftm/2',
            'created_at' => CarbonImmutable::parse('2026-05-15 10:00:00'),
        ]);

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_not_counted_when_not_first_time_meeting(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => false,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://reports.example.com/ftm/3',
            'created_at' => CarbonImmutable::parse('2026-05-15 10:00:00'),
        ]);

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_not_counted_when_decision_maker_absent(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => false,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://reports.example.com/ftm/4',
            'created_at' => CarbonImmutable::parse('2026-05-15 10:00:00'),
        ]);

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_not_counted_when_presentation_not_shown(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => false,
            'ftm_report_url' => 'https://reports.example.com/ftm/5',
            'created_at' => CarbonImmutable::parse('2026-05-15 10:00:00'),
        ]);

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_not_counted_when_report_url_is_null(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => null,
            'created_at' => CarbonImmutable::parse('2026-05-15 10:00:00'),
        ]);

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_excludes_other_users_ftm(): void
    {
        $user = $this->manager();
        $other = $this->manager();
        $this->countedFtm($user);
        // A perfectly-valid FTM that belongs to a colleague → not counted for $user.
        $this->countedFtm($other);

        $this->assertSame(1, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_excludes_ftm_outside_the_period(): void
    {
        $user = $this->manager();
        $this->countedFtm($user); // inside window
        $this->countedFtm($user, CarbonImmutable::parse('2026-04-20 10:00:00')); // before window
        $this->countedFtm($user, CarbonImmutable::parse('2026-06-03 10:00:00')); // after window

        $this->assertSame(1, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_counts_multiple_valid_ftm_in_period(): void
    {
        $user = $this->manager();
        $this->countedFtm($user, CarbonImmutable::parse('2026-05-05 09:00:00'));
        $this->countedFtm($user, CarbonImmutable::parse('2026-05-12 09:00:00'));
        $this->countedFtm($user, CarbonImmutable::parse('2026-05-28 09:00:00'));

        $this->assertSame(3, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    public function test_returns_zero_when_no_activities(): void
    {
        $user = $this->manager();

        $this->assertSame(0, $this->service()->countFtmForUser($user->id, $this->from, $this->to));
    }

    // ---- feedForUser ----

    public function test_feed_is_scoped_to_the_user(): void
    {
        $user = $this->manager();
        $other = $this->manager();
        Activity::factory()->task()->responsibleOf($user)->count(2)->create();
        Activity::factory()->task()->responsibleOf($other)->count(3)->create();

        $feed = $this->service()->feedForUser($user->id, []);

        $this->assertSame(2, $feed->total());
        foreach ($feed->items() as $item) {
            $this->assertSame($user->id, $item->responsible_id);
        }
    }

    public function test_feed_kind_filter_keeps_only_that_kind(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->count(2)->create();
        Activity::factory()->call()->responsibleOf($user)->count(3)->create();

        $feed = $this->service()->feedForUser($user->id, ['kind' => 'meeting']);

        $this->assertSame(2, $feed->total());
    }

    public function test_feed_kind_all_returns_every_kind(): void
    {
        $user = $this->manager();
        Activity::factory()->meeting()->responsibleOf($user)->create();
        Activity::factory()->call()->responsibleOf($user)->create();
        Activity::factory()->note()->responsibleOf($user)->create();

        $this->assertSame(3, $this->service()->feedForUser($user->id, ['kind' => 'all'])->total());
        // null kind behaves the same as 'all'.
        $this->assertSame(3, $this->service()->feedForUser($user->id, [])->total());
    }

    public function test_feed_period_filter_restricts_by_created_at(): void
    {
        $user = $this->manager();
        Activity::factory()->task()->responsibleOf($user)->create(['created_at' => CarbonImmutable::parse('2026-05-10 10:00:00')]);
        Activity::factory()->task()->responsibleOf($user)->create(['created_at' => CarbonImmutable::parse('2026-04-10 10:00:00')]);
        Activity::factory()->task()->responsibleOf($user)->create(['created_at' => CarbonImmutable::parse('2026-06-10 10:00:00')]);

        $feed = $this->service()->feedForUser($user->id, ['from' => $this->from, 'to' => $this->to]);

        $this->assertSame(1, $feed->total());
    }

    public function test_feed_ftm_only_returns_only_counted_ftm(): void
    {
        $user = $this->manager();
        $this->countedFtm($user);
        // A meeting missing the report url → not a counted FTM.
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => null,
        ]);
        // A plain call → never a counted FTM.
        Activity::factory()->call()->responsibleOf($user)->create();

        $feed = $this->service()->feedForUser($user->id, ['ftm_only' => true]);

        $this->assertSame(1, $feed->total());
    }

    public function test_feed_without_ftm_only_includes_non_ftm(): void
    {
        $user = $this->manager();
        $this->countedFtm($user);
        Activity::factory()->call()->responsibleOf($user)->create();

        // No ftm_only filter → both rows are present.
        $this->assertSame(2, $this->service()->feedForUser($user->id, ['ftm_only' => false])->total());
    }

    public function test_feed_orders_by_created_at_desc(): void
    {
        $user = $this->manager();
        $older = Activity::factory()->task()->responsibleOf($user)->create(['created_at' => CarbonImmutable::parse('2026-05-01 10:00:00')]);
        $newer = Activity::factory()->task()->responsibleOf($user)->create(['created_at' => CarbonImmutable::parse('2026-05-20 10:00:00')]);

        $items = $this->service()->feedForUser($user->id, [])->items();

        $this->assertSame($newer->id, $items[0]->id);
        $this->assertSame($older->id, $items[1]->id);
    }

    public function test_feed_respects_per_page(): void
    {
        $user = $this->manager();
        Activity::factory()->task()->responsibleOf($user)->count(5)->create();

        $feed = $this->service()->feedForUser($user->id, [], 2);

        $this->assertSame(2, $feed->perPage());
        $this->assertCount(2, $feed->items());
        $this->assertSame(5, $feed->total());
        $this->assertSame(3, $feed->lastPage());
    }

    public function test_feed_combines_kind_period_and_ftm_only(): void
    {
        $user = $this->manager();
        // In-window counted FTM → the single expected match.
        $this->countedFtm($user, CarbonImmutable::parse('2026-05-15 10:00:00'));
        // Counted FTM but out of window.
        $this->countedFtm($user, CarbonImmutable::parse('2026-06-15 10:00:00'));
        // In-window meeting, but not a counted FTM (no report url).
        Activity::factory()->meeting()->responsibleOf($user)->create([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => null,
            'created_at' => CarbonImmutable::parse('2026-05-18 10:00:00'),
        ]);

        $feed = $this->service()->feedForUser($user->id, [
            'kind' => 'meeting',
            'from' => $this->from,
            'to' => $this->to,
            'ftm_only' => true,
        ]);

        $this->assertSame(1, $feed->total());
    }
}
