<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\SalesPulse\Services\DaySnapshotService;
use App\Domain\SalesPulse\Services\MetricsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AmoPipelineSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesPulseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke coverage for SalesPulseDemoSeeder: it produces a today-anchored dataset in
 * the two AMO funnels under the demo managers such that DaySnapshotService::
 * collectDay yields a non-empty plan AND fact, and MetricsService computes non-zero
 * numbers — i.e. the bot commands will show live data. Also asserts idempotency.
 */
class SalesPulseDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> sales pipeline ids (MACRO Global + MACRO AI Global) */
    private function funnelIds(): array
    {
        return Pipeline::query()
            ->sales()
            ->whereIn('name', ['MACRO Global', 'MACRO AI Global'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function seedAll(): void
    {
        // RolePermission is needed because the seeder syncs the Manager role.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);
        $this->seed(SalesPulseDemoSeeder::class);
    }

    private function manager(): User
    {
        return User::query()->where('email', 'manager1@mgcrm.test')->firstOrFail();
    }

    public function test_seeder_creates_managers_deals_and_today_activities(): void
    {
        $this->seedAll();

        // 3 managers.
        foreach (['manager1@mgcrm.test', 'manager2@mgcrm.test', 'manager3@mgcrm.test'] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email]);
        }

        // Deals seeded into the AMO funnels only.
        $funnelIds = $this->funnelIds();
        $this->assertCount(2, $funnelIds);

        $dealCount = Deal::query()->whereIn('pipeline_id', $funnelIds)->count();
        $this->assertGreaterThan(0, $dealCount);

        // Every deal lives in one of the two AMO funnels (none leaked to Продажи).
        $prodazhi = Pipeline::query()->where('name', 'Продажи')->firstOrFail();
        $this->assertSame(
            0,
            Deal::query()->where('pipeline_id', $prodazhi->id)->count(),
            'demo deals must not land in the archived Продажи funnel',
        );

        // Today's stage-history transitions exist.
        $this->assertGreaterThan(0, DealStageHistory::query()->count());

        // FTM meetings completed today exist (announcer / MeetingDone signal).
        $this->assertGreaterThan(
            0,
            Activity::query()
                ->where('kind', ActivityType::Meeting->value)
                ->where('is_first_time_meeting', true)
                ->whereNotNull('completed_at')
                ->count(),
        );

        // Notes created today exist ("есть заметка").
        $this->assertGreaterThan(
            0,
            Activity::query()->where('kind', ActivityType::Note->value)->count(),
        );
    }

    public function test_collect_day_yields_non_empty_plan_and_fact_for_a_manager(): void
    {
        $this->seedAll();

        $manager = $this->manager();
        $today = CarbonImmutable::now(config('salespulse.timezone', 'Asia/Dubai'));

        $snapshot = app(DaySnapshotService::class)->collectDay($manager, $today, $this->funnelIds());

        $this->assertNotEmpty($snapshot->plan, 'manager must have a non-empty day plan');
        $this->assertNotEmpty($snapshot->fact, 'manager must have at least one completed (fact) task today');

        // Every plan row is a real-work, deal-bound activity of this manager.
        foreach ($snapshot->plan as $row) {
            $this->assertContains($row->kind, ActivityType::taskLikeValues());
            $this->assertNotNull($row->dealId);
        }
    }

    public function test_metrics_are_non_zero_for_a_manager(): void
    {
        $this->seedAll();

        $manager = $this->manager();
        $today = CarbonImmutable::now(config('salespulse.timezone', 'Asia/Dubai'));

        $snapshot = app(DaySnapshotService::class)->collectDay($manager, $today, $this->funnelIds());

        // Deals on the plan that carry a note today → suppresses missed-flagging.
        $notesByDeal = [];
        foreach ($snapshot->plan as $row) {
            if ($row->dealId !== null) {
                $hasNote = Activity::query()
                    ->where('kind', ActivityType::Note->value)
                    ->where('target_type', ActivityTargetType::Deal->value)
                    ->where('target_id', $row->dealId)
                    ->exists();
                if ($hasNote) {
                    $notesByDeal[$row->dealId] = true;
                }
            }
        }

        // Treat the same snapshot as morning plan + evening collect — done tasks
        // count toward activityDone, so the smoke metric is non-zero.
        $metrics = app(MetricsService::class)->compute($snapshot, $snapshot, $notesByDeal);

        $this->assertGreaterThan(0, $metrics->activityTotal, 'plan must have task-like activities');
        $this->assertGreaterThan(0, $metrics->activityDone, 'some plan tasks are completed today');
        $this->assertGreaterThan(0, $metrics->companies, 'plan touches at least one deal');
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedAll();

        $dealsAfterFirst = Deal::query()->count();
        $activitiesAfterFirst = Activity::query()->count();
        $historyAfterFirst = DealStageHistory::query()->count();

        // Re-run: no duplicates.
        $this->seed(SalesPulseDemoSeeder::class);

        $this->assertSame($dealsAfterFirst, Deal::query()->count());
        $this->assertSame($activitiesAfterFirst, Activity::query()->count());
        $this->assertSame($historyAfterFirst, DealStageHistory::query()->count());
    }

    public function test_no_op_when_amo_funnels_absent(): void
    {
        // Only roles seeded — no AMO funnels. The seeder must return cleanly.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SalesPulseDemoSeeder::class);

        $this->assertSame(0, Deal::query()->count());
    }
}
