<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the S1.7 Sales Dashboard endpoint.
 *
 * Tests JSON structure, visibility scope (manager vs director), period filter,
 * deals_without_tasks count, forecast correctness, and xlsx export.
 */
class DashboardEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function salesPipeline(): Pipeline
    {
        return Pipeline::factory()->create([
            'kind' => PipelineKind::Sales->value,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function openStage(Pipeline $pipeline, int $sortOrder = 1): PipelineStage
    {
        return PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => $sortOrder,
            'name' => 'Warm leads',
        ]);
    }

    private function wonStage(Pipeline $pipeline): PipelineStage
    {
        return PipelineStage::factory()->won()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 99,
            'name' => 'Won',
        ]);
    }

    private function deal(User $owner, Pipeline $pipeline, PipelineStage $stage, int $amount = 100_000): Deal
    {
        return Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $owner->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => $amount,
            'stage_changed_at' => now(),
        ]);
    }

    // =========================================================================
    // Auth
    // =========================================================================

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/sales/dashboard')->assertUnauthorized();
    }

    public function test_unauthenticated_export_returns_401(): void
    {
        $this->getJson('/api/sales/dashboard.xlsx')->assertUnauthorized();
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    public function test_response_json_structure_matches_contract(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $this->openStage($pipeline);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['pipeline', 'period', 'base_currency', 'multi_currency_warning', 'generated_at'],
                'status_groups',
                'funnel' => ['stages', 'total_active', 'total_won', 'total_lost'],
                'forecast' => ['total_weighted_kopecks', 'hot_kopecks', 'warm_kopecks', 'trial_kopecks', 'by_stage'],
                'top_products' => ['labels', 'datasets', 'meta'],
                'top_managers' => ['labels', 'datasets', 'meta'],
                'deals_without_tasks' => ['count', 'filter_url'],
            ]);
    }

    public function test_status_groups_has_4_keys(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $this->openStage($pipeline);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $groups = $response->json('status_groups');
        $this->assertCount(4, $groups);

        $keys = array_column($groups, 'key');
        $this->assertContains('active', $keys);
        $this->assertContains('won', $keys);
        $this->assertContains('lost', $keys);
        $this->assertContains('total', $keys);
    }

    // =========================================================================
    // Visibility scope
    // =========================================================================

    public function test_manager_sees_only_own_deals(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // manager owns 2 deals; other owns 3 deals.
        $this->deal($manager, $pipeline, $stage, 50_000);
        $this->deal($manager, $pipeline, $stage, 50_000);
        $this->deal($other, $pipeline, $stage, 100_000);
        $this->deal($other, $pipeline, $stage, 100_000);
        $this->deal($other, $pipeline, $stage, 100_000);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        // Manager should see only 2 deals.
        $this->assertSame(2, $activeGroup['count']);
    }

    public function test_director_sees_all_deals(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $m1 = User::factory()->create(['role' => Role::Manager]);
        $m2 = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        $this->deal($m1, $pipeline, $stage);
        $this->deal($m2, $pipeline, $stage);
        $this->deal($director, $pipeline, $stage);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        $this->assertSame(3, $activeGroup['count']);
    }

    // =========================================================================
    // Period filter
    // =========================================================================

    public function test_period_filter_current_month_restricts_results(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Deal inside current month.
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 100_000,
            'stage_changed_at' => now(),
        ]);

        // Deal outside current month (last year).
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 999_999,
            'stage_changed_at' => now()->subYear(),
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?period=current_month&pipeline_id='.$pipeline->id)
            ->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        // Only 1 deal in current month.
        $this->assertSame(1, $activeGroup['count']);
        $this->assertSame(100_000, $activeGroup['amount_kopecks']);
    }

    public function test_won_deal_counted_by_fact_date_not_stage_changed_at(): void
    {
        // A deal whose stage last changed LAST month but was actually closed THIS
        // month must count in the current-month won group (period keys on the
        // effective fact date, not stage_changed_at).
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $won = $this->wonStage($pipeline);

        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $won->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 250_000,
            'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(3),
            'closed_at' => now()->startOfMonth()->addDays(2),
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?period=current_month&pipeline_id='.$pipeline->id)
            ->assertOk();

        $wonGroup = collect($response->json('status_groups'))->firstWhere('key', 'won');
        $this->assertSame(1, $wonGroup['count']);
        $this->assertSame(250_000, $wonGroup['amount_kopecks']);
    }

    public function test_won_deal_without_fact_date_falls_back_to_stage_changed_at(): void
    {
        // No fact date captured (closed_at/signed_at/paid_at NULL) → the deal must
        // still be counted via the stage_changed_at fallback, not dropped.
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $won = $this->wonStage($pipeline);

        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $won->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 90_000,
            'stage_changed_at' => now(),
            'closed_at' => null,
            'signed_at' => null,
            'paid_at' => null,
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?period=current_month&pipeline_id='.$pipeline->id)
            ->assertOk();

        $wonGroup = collect($response->json('status_groups'))->firstWhere('key', 'won');
        $this->assertSame(1, $wonGroup['count']);
        $this->assertSame(90_000, $wonGroup['amount_kopecks']);
    }

    public function test_period_filter_last_month_shifts_window(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Deal from last month.
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 77_000,
            'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(5),
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?period=last_month&pipeline_id='.$pipeline->id)
            ->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        $this->assertSame(1, $activeGroup['count']);
        $this->assertSame(77_000, $activeGroup['amount_kopecks']);
    }

    // =========================================================================
    // Trend (previous period) scoping
    // =========================================================================

    public function test_trend_prev_period_ignores_soft_deleted_deals(): void
    {
        // 3 active deals this month; previous month has 3 live + 1 soft-deleted
        // deal. The scoped trend must compare 3 (current) vs 3 (prev, live only),
        // i.e. trend_pct = 0.0 — NOT 3 vs 4 (-25%) that the raw query produced.
        // Prior count (3) is at the V6 min-throughput threshold so the delta is
        // still reported.
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Current period — 3 active deals.
        for ($i = 0; $i < 3; $i++) {
            Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $director->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => 100_000,
                'stage_changed_at' => now(),
            ]);
        }

        // Previous period — 3 live deals.
        for ($i = 0; $i < 3; $i++) {
            Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $director->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => 100_000,
                'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(3),
            ]);
        }

        // Previous period — 1 soft-deleted deal (must NOT count in prev).
        $deleted = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 100_000,
            'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(4),
        ]);
        $deleted->delete();

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?period=current_month&pipeline_id='.$pipeline->id)
            ->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        $this->assertSame(3, $activeGroup['count']);
        // 3 (current) vs 3 (prev live) → 0%. Raw-query bug would yield -25.
        // JSON serializes 0.0 as 0 (no fractional part) → compare loosely.
        $this->assertEquals(0.0, $activeGroup['trend_pct']);
    }

    public function test_trend_prev_period_respects_manager_id_filter(): void
    {
        // Director filters by a single manager. The previous-period denominator
        // must be scoped to that manager too — not all owners. Prior counts are
        // kept at/above the V6 min-throughput threshold so the delta is reported.
        $director = User::factory()->create(['role' => Role::Director]);
        $mgr = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Current period: mgr has 4 deals.
        for ($i = 0; $i < 4; $i++) {
            $this->deal($mgr, $pipeline, $stage);
        }

        // Previous period: mgr has 3 deals, other has 5 (must be excluded).
        for ($i = 0; $i < 3; $i++) {
            Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $mgr->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => 100_000,
                'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(2),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            Deal::factory()->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $other->id,
                'company_id' => Company::factory()->create()->id,
                'currency' => 'RUB',
                'amount' => 100_000,
                'stage_changed_at' => now()->startOfMonth()->subMonth()->addDays(2),
            ]);
        }

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson(
            '/api/sales/dashboard?period=current_month&pipeline_id='.$pipeline->id.'&manager_id='.$mgr->id
        )->assertOk();

        $activeGroup = collect($response->json('status_groups'))->firstWhere('key', 'active');
        $this->assertSame(4, $activeGroup['count']);
        // 4 (current, mgr) vs 3 (prev, mgr only) → +33.3%. Unscoped prev (8) → -50.
        $this->assertEquals(33.3, $activeGroup['trend_pct']);
    }

    // =========================================================================
    // manager_id filter validation
    // =========================================================================

    public function test_manager_cannot_filter_by_other_manager_id(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->salesPipeline();
        $this->openStage($pipeline);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id.'&manager_id='.$other->id)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('manager_id');
    }

    // =========================================================================
    // Deals without tasks
    // =========================================================================

    public function test_deals_without_tasks_count_correct(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // 3 deals: 1 has an open task, 2 don't.
        $withTask = $this->deal($director, $pipeline, $stage);
        Activity::factory()
            ->task()
            ->forDeal($withTask)
            ->create(['is_closed' => false]);

        $this->deal($director, $pipeline, $stage);
        $this->deal($director, $pipeline, $stage);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $this->assertSame(2, $response->json('deals_without_tasks.count'));
        $this->assertStringContainsString('only_no_task=1', $response->json('deals_without_tasks.filter_url'));
    }

    // =========================================================================
    // Forecast
    // =========================================================================

    public function test_forecast_weighted_sum_non_negative(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        $this->deal($director, $pipeline, $stage, 500_000);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $this->assertGreaterThanOrEqual(0, $response->json('forecast.total_weighted_kopecks'));
    }

    public function test_forecast_uses_probability_keywords(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();

        // Hot stage → probability 0.7.
        $hotStage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 1,
            'name' => 'Hot deals',
        ]);
        $pipeline->load('stages');

        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $hotStage->id,
            'owner_user_id' => $director->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'amount' => 100_000,
            'stage_changed_at' => now(),
        ]);

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        // weighted = 100_000 * 0.7 = 70_000
        $this->assertSame(70_000, $response->json('forecast.total_weighted_kopecks'));
        // hot_kopecks = 100_000 (sum of deals in hot bucket)
        $this->assertSame(100_000, $response->json('forecast.hot_kopecks'));
    }

    // =========================================================================
    // Top-N
    // =========================================================================

    public function test_top_products_count_lte_11(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Create 15 products with deals.
        for ($i = 1; $i <= 15; $i++) {
            $product = Product::factory()->create(['name' => "Product {$i}"]);
            $deal = $this->deal($director, $pipeline, $stage, $i * 10_000);
            DealProduct::factory()->create([
                'deal_id' => $deal->id,
                'product_id' => $product->id,
                'amount' => $i * 10_000,
                'currency' => 'RUB',
            ]);
        }

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $labels = $response->json('top_products.labels');
        // Maximum 10 + «Другие» = 11.
        $this->assertLessThanOrEqual(11, count($labels));
        $this->assertSame('Другие', end($labels));
    }

    public function test_top_managers_count_lte_11(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = $this->openStage($pipeline);

        // Create 12 managers with deals.
        for ($i = 1; $i <= 12; $i++) {
            $mgr = User::factory()->create(['role' => Role::Manager, 'full_name' => "Manager {$i}"]);
            $this->deal($mgr, $pipeline, $stage, $i * 10_000);
        }

        Sanctum::actingAs($director, ['*']);

        $response = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $labels = $response->json('top_managers.labels');
        $this->assertLessThanOrEqual(11, count($labels));
    }

    // =========================================================================
    // Pipeline ID filter
    // =========================================================================

    public function test_pipeline_id_filter_isolates_results(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $p1 = $this->salesPipeline();
        $p2 = $this->salesPipeline();
        $s1 = $this->openStage($p1);
        $s2 = $this->openStage($p2);

        $this->deal($director, $p1, $s1, 100_000);
        $this->deal($director, $p2, $s2, 999_999);

        Sanctum::actingAs($director, ['*']);

        $r1 = $this->getJson('/api/sales/dashboard?pipeline_id='.$p1->id)->assertOk();
        $r2 = $this->getJson('/api/sales/dashboard?pipeline_id='.$p2->id)->assertOk();

        $amt1 = collect($r1->json('status_groups'))->firstWhere('key', 'active')['amount_kopecks'];
        $amt2 = collect($r2->json('status_groups'))->firstWhere('key', 'active')['amount_kopecks'];

        $this->assertSame(100_000, $amt1);
        $this->assertSame(999_999, $amt2);
    }

    // =========================================================================
    // xlsx export
    // =========================================================================

    public function test_export_xlsx_returns_200_with_content_type(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $this->openStage($pipeline);
        Sanctum::actingAs($director, ['*']);

        $response = $this->get('/api/sales/dashboard.xlsx?pipeline_id='.$pipeline->id);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type') ?? ''
        );
    }

    public function test_export_xlsx_scoped_to_visibility(): void
    {
        // Manager can only export their own deals — the xlsx download should not 403.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $pipeline = $this->salesPipeline();
        $this->openStage($pipeline);
        Sanctum::actingAs($manager, ['*']);

        $this->get('/api/sales/dashboard.xlsx?pipeline_id='.$pipeline->id)
            ->assertOk();
    }
}
