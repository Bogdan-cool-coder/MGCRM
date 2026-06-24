<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\DashboardFilters;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Top-managers aggregation correctness: grouping by users.id (not full_name),
 * service-account exclusion, and archived-deal exclusion from aggregates.
 */
class SalesDashboardTopManagersTest extends TestCase
{
    use RefreshDatabase;

    private SalesDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SalesDashboardService::class);
    }

    private function fullRangeFilters(int $pipelineId): DashboardFilters
    {
        return new DashboardFilters(
            period: 'current_month',
            dateFrom: now()->subYears(10),
            dateTo: now()->addYears(10),
            pipelineId: $pipelineId,
            managerId: null,
        );
    }

    private function dealFor(Pipeline $pipeline, PipelineStage $stage, User $owner, int $amount, array $extra = []): Deal
    {
        return Deal::factory()->create(array_merge([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => $owner->id,
            'currency' => 'RUB',
            'amount' => $amount,
            'stage_changed_at' => now(),
        ], $extra));
    }

    public function test_homonymous_managers_are_not_merged(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        // Two distinct managers sharing the exact same display name.
        $a = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Ivan Petrov']);
        $b = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Ivan Petrov']);

        $this->dealFor($pipeline, $stage, $a, 100_000);
        $this->dealFor($pipeline, $stage, $b, 300_000);

        $warning = false;
        $result = $this->service->topManagers(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        // Grouping by users.id keeps them as two separate bars (both labelled the
        // same name) — not one merged 400_000 bar.
        $this->assertCount(2, $result['datasets'][0]['data']);
        $this->assertSame([300_000, 100_000], $result['datasets'][0]['data']);
    }

    public function test_service_account_managers_are_excluded(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        $real = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Real Manager']);
        $bot = User::factory()->create(['role' => Role::Manager, 'full_name' => 'AMO Import', 'is_service' => true]);

        $this->dealFor($pipeline, $stage, $real, 100_000);
        $this->dealFor($pipeline, $stage, $bot, 999_000);

        $warning = false;
        $result = $this->service->topManagers(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        $this->assertSame(['Real Manager'], $result['labels']);
        $this->assertSame([100_000], $result['datasets'][0]['data']);
    }

    public function test_archived_deals_excluded_from_top_managers(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        $manager = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Alpha']);

        $this->dealFor($pipeline, $stage, $manager, 100_000);
        $this->dealFor($pipeline, $stage, $manager, 500_000, ['archived_at' => now()]);

        $warning = false;
        $result = $this->service->topManagers(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        // Only the non-archived 100_000 deal counts.
        $this->assertSame(['Alpha'], $result['labels']);
        $this->assertSame([100_000], $result['datasets'][0]['data']);
    }

    public function test_archived_deals_excluded_from_status_groups(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 1,
            'is_won' => false,
            'is_lost' => false,
        ]);

        $manager = User::factory()->create(['role' => Role::Manager]);

        $this->dealFor($pipeline, $stage, $manager, 100_000);
        $this->dealFor($pipeline, $stage, $manager, 500_000, ['archived_at' => now()]);

        $warning = false;
        $groups = $this->service->statusGroups(
            $pipeline->id,
            VisibilityScope::All,
            $admin,
            $this->fullRangeFilters($pipeline->id),
            $warning,
        );

        $active = collect($groups)->firstWhere('key', 'active');
        $this->assertNotNull($active);
        $this->assertSame(1, $active['count']);
        $this->assertSame(100_000, $active['amount_kopecks']);
    }
}
