<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Services\ManagerKpiService;
use App\Http\Resources\Sales\ActivityFeedItemResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MINOR-9 cross-path consistency: the five-condition FTM predicate is
 * single-sourced on the Activity model (FTM_BOOLEAN_FLAGS + the kind=meeting and
 * non-null report-url checks, consumed by BOTH Activity::scopeFtmCounted() and
 * Activity::qualifiesAsFtm()). The two Sales consumers — ManagerKpiService and
 * ActivityFeedItemResource — delegate to qualifiesAsFtm() and must never inline
 * the rule.
 *
 * These tests assert that for the SAME row, all four surfaces agree:
 *   1. the query scope (Activity::ftmCounted SQL)
 *   2. Activity::qualifiesAsFtm() (object predicate)
 *   3. ManagerKpiService::ftmCounted() (Sales consumer)
 *   4. ActivityFeedItemResource ftm_counted flag (Sales consumer)
 *
 * If any of the four ever drifts (e.g. a flag is added to the scope but forgotten
 * in the predicate) these tests fail.
 */
class FtmPredicateConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private ManagerKpiService $kpiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kpiService = app(ManagerKpiService::class);
    }

    /**
     * Build a persisted FTM-qualified meeting, applying the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeFtm(array $overrides = []): Activity
    {
        $user = User::factory()->create();

        return Activity::factory()->meeting()->responsibleOf($user)->create(array_merge([
            'is_first_time_meeting' => true,
            'ftm_decision_maker_attended' => true,
            'ftm_presentation_shown' => true,
            'ftm_report_url' => 'https://example.com/report/1',
        ], $overrides));
    }

    /**
     * Evaluate all four FTM surfaces for one persisted activity and assert they
     * agree with the expected boolean.
     */
    private function assertAllPathsAgree(Activity $activity, bool $expected): void
    {
        // 1. Query scope (SQL form) — does the row survive the scope?
        $countedByScope = Activity::query()
            ->whereKey($activity->getKey())
            ->ftmCounted()
            ->exists();

        // 2. Object predicate (PHP form), single source.
        $countedByPredicate = Activity::qualifiesAsFtm($activity);

        // 3. Sales consumer: ManagerKpiService.
        $countedByKpi = $this->kpiService->ftmCounted($activity);

        // 4. Sales consumer: feed resource flag.
        $flaggedByFeed = (new ActivityFeedItemResource($activity))
            ->toArray(request())['ftm_counted'];

        $this->assertSame($expected, $countedByScope, 'query scope disagreed');
        $this->assertSame($expected, $countedByPredicate, 'qualifiesAsFtm disagreed');
        $this->assertSame($expected, $countedByKpi, 'ManagerKpiService::ftmCounted disagreed');
        $this->assertSame($expected, $flaggedByFeed, 'ActivityFeedItemResource flag disagreed');

        // Belt-and-braces: all four must be identical to each other regardless of
        // the expected value, so a future regression that flips two of them in the
        // same direction is still caught.
        $this->assertSame(
            [$countedByScope, $countedByPredicate, $countedByKpi, $flaggedByFeed],
            array_fill(0, 4, $expected),
            'the four FTM surfaces drifted apart',
        );
    }

    // -------------------------------------------------------------------------
    // Positive: all five conditions satisfied → counted by every path.
    // -------------------------------------------------------------------------

    public function test_all_paths_count_a_fully_qualified_ftm(): void
    {
        $this->assertAllPathsAgree($this->makeFtm(), true);
    }

    // -------------------------------------------------------------------------
    // Each single failing condition → excluded by every path, identically.
    // -------------------------------------------------------------------------

    public function test_all_paths_exclude_a_non_meeting(): void
    {
        // Same five FTM attributes, but kind=call — the kind=meeting gate must
        // exclude it on every path.
        $this->assertAllPathsAgree($this->makeFtm(['kind' => 'call']), false);
    }

    public function test_all_paths_exclude_when_not_first_time(): void
    {
        $this->assertAllPathsAgree($this->makeFtm(['is_first_time_meeting' => false]), false);
    }

    public function test_all_paths_exclude_when_decision_maker_absent(): void
    {
        $this->assertAllPathsAgree($this->makeFtm(['ftm_decision_maker_attended' => false]), false);
    }

    public function test_all_paths_exclude_when_presentation_not_shown(): void
    {
        $this->assertAllPathsAgree($this->makeFtm(['ftm_presentation_shown' => false]), false);
    }

    public function test_all_paths_exclude_when_report_url_null(): void
    {
        $this->assertAllPathsAgree($this->makeFtm(['ftm_report_url' => null]), false);
    }
}
