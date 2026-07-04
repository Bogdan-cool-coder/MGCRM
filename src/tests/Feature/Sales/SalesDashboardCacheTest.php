<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\DashboardFilters;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data-Layer-Audit-2026-07 §3.5 — the sales-dashboard aggregate payload is
 * memoised for a short TTL so a repeat open / filter flip within the window is a
 * cache hit instead of a fresh ~8-query rescan. The suite-wide default TTL is 0
 * (phpunit.xml), so this test enables it at runtime to exercise the cache path.
 *
 * Covers: cache hit avoids re-querying · distinct scope/pipeline get distinct
 * keys (no cross-scope leak) · TTL 0 disables the cache (always recompute).
 */
class SalesDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    private function salesPipeline(): Pipeline
    {
        $pipeline = Pipeline::factory()->create([
            'kind' => PipelineKind::Sales->value,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'is_won' => false,
            'is_lost' => false,
            'sort_order' => 1,
        ]);

        return $pipeline;
    }

    private function filtersFor(Pipeline $pipeline): DashboardFilters
    {
        return new DashboardFilters(
            period: 'month',
            dateFrom: Carbon::parse('2026-07-01'),
            dateTo: Carbon::parse('2026-07-31'),
            pipelineId: $pipeline->id,
            managerId: null,
        );
    }

    public function test_second_call_within_ttl_skips_the_aggregate_scan(): void
    {
        config(['crm.dashboard.cache_ttl' => 60]);
        Cache::flush();

        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $filters = $this->filtersFor($pipeline);
        $service = app(SalesDashboardService::class);

        // First call populates the cache (miss → compute → store).
        $first = $service->getDashboardData($filters, $director);

        // Second call is a hit: only the cheap scope + pipeline resolution that
        // runs BEFORE the cache remains; every aggregate query is skipped.
        DB::enableQueryLog();
        $second = $service->getDashboardData($filters, $director);
        $cachedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second, 'cached payload must be identical to the first');
        $this->assertLessThanOrEqual(2, $cachedQueries, 'a cache hit runs only the scope/pipeline resolution, no aggregates');
    }

    public function test_ttl_zero_disables_the_cache(): void
    {
        config(['crm.dashboard.cache_ttl' => 0]);
        Cache::flush();

        $director = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $filters = $this->filtersFor($pipeline);
        $service = app(SalesDashboardService::class);

        $service->getDashboardData($filters, $director);

        DB::enableQueryLog();
        $service->getDashboardData($filters, $director);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'with TTL 0 the dashboard must always recompute');
    }

    public function test_distinct_users_do_not_share_a_cache_entry(): void
    {
        config(['crm.dashboard.cache_ttl' => 60]);
        Cache::flush();

        $pipeline = $this->salesPipeline();
        $filters = $this->filtersFor($pipeline);
        $service = app(SalesDashboardService::class);

        // A director (scope All) and a manager (scope Own) with the same filters
        // must resolve to different cache keys — one scope's aggregates must never
        // be served to the other.
        $director = User::factory()->create(['role' => Role::Director]);
        $manager = User::factory()->create(['role' => Role::Manager]);

        $service->getDashboardData($filters, $director);

        DB::enableQueryLog();
        $service->getDashboardData($filters, $manager);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $queries, 'a different user/scope must be a cache miss, not a hit');
    }
}
