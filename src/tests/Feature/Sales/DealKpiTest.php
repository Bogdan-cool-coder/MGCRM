<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\DealKpiService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/deals/kpi — the funnel-wide KPI chip aggregate (SalesFunnel-spec
 * §5.1). The defining property: every chip counts the WHOLE filtered,
 * visibility-scoped funnel, not the single list page DealsKpiChips.vue sees today
 * (which silently undercounts past page 1). Coverage:
 *   - chips count the whole funnel, not one page (seed > per_page deals);
 *   - filters narrow KPI identically to the list (status / owner / country / budget / overdue / tags);
 *   - visibility scope honoured (Own sees own, Department sees subtree, All sees all);
 *   - in_work counts DISTINCT companies among non-won deals;
 *   - cat_s = S1 + S2 combined;
 *   - archived excluded by default;
 *   - pipeline_id defaults to the active sales pipeline when absent.
 */
class DealKpiTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private Pipeline $pipeline;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = $this->seedSalesPipeline();
        $this->director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($this->director, ['*']);
    }

    /** A deal owned by the director on the given stage code + company. */
    private function dealOn(string $stageCode, ?Company $company = null, array $attrs = []): Deal
    {
        return Deal::factory()->forOwner($this->director)->create(array_merge([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, $stageCode),
            'company_id' => ($company ?? Company::factory()->create())->id,
        ], $attrs));
    }

    /** @return array<string, mixed> the `data` payload of GET /api/deals/kpi */
    private function kpi(string $query = ''): array
    {
        return $this->getJson('/api/deals/kpi'.$query)->assertOk()->json('data');
    }

    // -------------------------------------------------------- whole funnel, not a page

    public function test_chips_count_the_whole_funnel_not_one_page(): void
    {
        // 30 deals — well past the list's default per_page of 25, so a page-local
        // chip would cap at 25. The KPI must report all 30.
        for ($i = 0; $i < 30; $i++) {
            $this->dealOn('new');
        }

        $data = $this->kpi();

        // 30 distinct companies, all non-won → in_work = 30 (> one list page).
        $this->assertSame(30, $data['in_work']);
        $this->assertGreaterThan(25, $data['in_work']);
    }

    // ----------------------------------------------------------------- full shape

    public function test_response_has_the_full_chip_shape(): void
    {
        $this->dealOn('new');

        $this->getJson('/api/deals/kpi')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'pipeline_id',
                    'in_work',
                    'cat_l',
                    'cat_m',
                    'cat_s',
                    'won',
                    'no_task',
                    'overdue',
                ],
            ]);
    }

    // ------------------------------------------------------------------- in_work

    public function test_in_work_counts_distinct_companies_among_non_won_deals(): void
    {
        $company = Company::factory()->create();

        // Two non-won deals on ONE company → in_work counts the company once.
        $this->dealOn('new', $company);
        $this->dealOn('qualify', $company);

        // A non-won deal on a SECOND company → +1.
        $this->dealOn('new');

        // A won deal must NOT count toward in_work.
        $this->dealOn('won');

        $this->assertSame(2, $this->kpi()['in_work']);
    }

    public function test_in_work_distinct_key_does_not_collide_across_companies(): void
    {
        // #4: in_work counts DISTINCT companies via COALESCE(company_id, -id). This
        // guards that the null-safe key never makes two distinct companies collapse
        // into one (the negative -id space can never alias a real positive company
        // id). Three separate companies, several deals each → exactly 3.
        $c1 = Company::factory()->create();
        $c2 = Company::factory()->create();
        $c3 = Company::factory()->create();

        $this->dealOn('new', $c1);
        $this->dealOn('qualify', $c1);
        $this->dealOn('new', $c2);
        $this->dealOn('new', $c3);
        $this->dealOn('qualify', $c3);

        $this->assertSame(3, $this->kpi()['in_work']);
    }

    // ----------------------------------------------------------------------- won

    public function test_won_counts_only_won_stage_deals(): void
    {
        $this->dealOn('won');
        $this->dealOn('won');
        $this->dealOn('new');
        $this->dealOn('lost');

        $this->assertSame(2, $this->kpi()['won']);
    }

    // ------------------------------------------------------------------ categories

    public function test_categories_count_by_company_category_code(): void
    {
        $this->dealOn('new', Company::factory()->create(['category_code' => 'L']));
        $this->dealOn('new', Company::factory()->create(['category_code' => 'M']));
        $this->dealOn('new', Company::factory()->create(['category_code' => 'M']));
        // cat_s = S1 + S2 combined.
        $this->dealOn('new', Company::factory()->create(['category_code' => 'S1']));
        $this->dealOn('new', Company::factory()->create(['category_code' => 'S2']));
        // Uncategorised company contributes to none of the category chips.
        $this->dealOn('new', Company::factory()->create(['category_code' => null]));

        $data = $this->kpi();

        $this->assertSame(1, $data['cat_l']);
        $this->assertSame(2, $data['cat_m']);
        $this->assertSame(2, $data['cat_s']);
    }

    // -------------------------------------------------------------------- no_task

    public function test_no_task_counts_deals_without_an_open_task(): void
    {
        // Has an open task → excluded from no_task.
        $withTask = $this->dealOn('new');
        Activity::factory()->task()->forDeal($withTask)
            ->create(['due_at' => now()->addDay(), 'is_closed' => false]);

        // No task at all → counted.
        $this->dealOn('new');

        // A closed task is not "open" → still counted as no_task.
        $withClosed = $this->dealOn('new');
        Activity::factory()->task()->forDeal($withClosed)->completed($this->director)
            ->create(['due_at' => now()->addDay(), 'is_closed' => true]);

        $this->assertSame(2, $this->kpi()['no_task']);
    }

    // -------------------------------------------------------------------- overdue

    public function test_overdue_counts_deals_with_an_overdue_task(): void
    {
        $overdue = $this->dealOn('new');
        Activity::factory()->task()->forDeal($overdue)->overdue()
            ->create(['due_at' => now()->subDays(2)]);

        $future = $this->dealOn('new');
        Activity::factory()->task()->forDeal($future)
            ->create(['due_at' => now()->addDays(3)]);

        $this->dealOn('new'); // no task — not overdue

        $this->assertSame(1, $this->kpi()['overdue']);
    }

    // --------------------------------------------------------------- filter parity

    public function test_filters_narrow_the_kpi_like_the_list(): void
    {
        // status=won narrows every chip to the won subset.
        $this->dealOn('won');
        $this->dealOn('won');
        $this->dealOn('new');

        $data = $this->kpi('?status=won');

        $this->assertSame(2, $data['won']);
        // in_work counts non-won deals; with status=won there are none.
        $this->assertSame(0, $data['in_work']);
    }

    public function test_owner_filter_narrows_the_kpi(): void
    {
        $manager = User::factory()->create(['role' => Role::Director]);

        Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
            'company_id' => Company::factory()->create()->id,
        ]);
        $this->dealOn('new'); // director's own — excluded by the owner filter

        $this->assertSame(1, $this->kpi("?owner_ids[]={$manager->id}")['in_work']);
    }

    public function test_country_filter_narrows_the_kpi(): void
    {
        $this->dealOn('new', Company::factory()->create(['country_code' => 'ru']));
        $this->dealOn('new', Company::factory()->create(['country_code' => 'kz']));

        $this->assertSame(1, $this->kpi('?country=ru')['in_work']);
    }

    public function test_budget_filter_narrows_the_kpi(): void
    {
        $this->dealOn('new', null, ['amount' => 50_000, 'amount_locked' => true]);
        $this->dealOn('new', null, ['amount' => 500_000, 'amount_locked' => true]);
        $this->dealOn('new', null, ['amount' => 5_000_000, 'amount_locked' => true]);

        // [100_000 .. 1_000_000] → only the mid deal.
        $this->assertSame(1, $this->kpi('?budget_from=100000&budget_to=1000000')['in_work']);
    }

    public function test_tags_filter_narrows_the_kpi(): void
    {
        $this->dealOn('new', null, ['tags' => ['vip']]);
        $this->dealOn('new', null, ['tags' => ['urgent']]);
        $this->dealOn('new', null, ['tags' => ['cold']]);

        $this->assertSame(2, $this->kpi('?tags[]=vip&tags[]=urgent')['in_work']);
    }

    public function test_only_overdue_filter_narrows_the_kpi(): void
    {
        $overdue = $this->dealOn('new');
        Activity::factory()->task()->forDeal($overdue)->overdue()
            ->create(['due_at' => now()->subDay()]);

        $this->dealOn('new'); // no overdue task — excluded by only_overdue

        $this->assertSame(1, $this->kpi('?only_overdue=true')['in_work']);
    }

    // ---------------------------------------------------------------- archived

    public function test_archived_deals_are_excluded_by_default(): void
    {
        $this->dealOn('new');
        $this->dealOn('new', null, ['archived_at' => now()]);

        // Default (no ?archived) hides the archived deal.
        $this->assertSame(1, $this->kpi()['in_work']);
        // ?archived=true returns ONLY the archived one.
        $this->assertSame(1, $this->kpi('?archived=true')['in_work']);
    }

    // ----------------------------------------------------------- pipeline default

    public function test_pipeline_id_defaults_to_the_active_sales_pipeline(): void
    {
        $this->dealOn('new');

        $data = $this->kpi();

        // Absent pipeline_id resolves to the page's funnel and is echoed back.
        $this->assertSame((int) $this->pipeline->id, $data['pipeline_id']);
        $this->assertSame(1, $data['in_work']);
    }

    public function test_explicit_pipeline_id_is_echoed_and_honoured(): void
    {
        $this->dealOn('new');

        $data = $this->kpi("?pipeline_id={$this->pipeline->id}");

        $this->assertSame((int) $this->pipeline->id, $data['pipeline_id']);
    }

    // ------------------------------------------------------------------- auth

    public function test_unauthenticated_request_is_rejected(): void
    {
        // Drop the acting user (the trait set one in setUp).
        app('auth')->forgetGuards();

        $this->getJson('/api/deals/kpi')->assertUnauthorized();
    }

    // -------------------------------------------------------- visibility scope

    public function test_own_scope_counts_only_the_users_own_deals(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $foreign = User::factory()->create(['role' => Role::Manager]);

        // Foreign deal — a Manager (Own scope) must never count it.
        Deal::factory()->forOwner($foreign)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
            'company_id' => Company::factory()->create()->id,
        ]);
        Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
            'company_id' => Company::factory()->create()->id,
        ]);

        Sanctum::actingAs($manager, ['*']);

        // Manager resolves to Own scope; even if the filter named the foreign
        // owner, the scope hides it.
        $this->assertSame(1, $this->kpi("?owner_ids[]={$foreign->id}&owner_ids[]={$manager->id}")['in_work']);
    }

    public function test_department_scope_counts_the_subtree_via_service(): void
    {
        // Department scope is never produced by the HTTP layer for these roles, so
        // exercise it directly on the service (mirrors ActivityScopeTest). A parent
        // department with a child (subtree); a third manager outside it.
        $service = app(DealKpiService::class);

        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);

        $head = User::factory()->create(['role' => Role::Manager, 'department_id' => $parent->id]);
        $sub = User::factory()->create(['role' => Role::Manager, 'department_id' => $child->id]);
        $outsider = User::factory()->create(['role' => Role::Manager, 'department_id' => null]);

        foreach ([$head, $sub, $outsider] as $owner) {
            Deal::factory()->forOwner($owner)->create([
                'pipeline_id' => $this->pipeline->id,
                'stage_id' => $this->stageCode($this->pipeline, 'new'),
                'company_id' => Company::factory()->create()->id,
                'department_id' => $owner->department_id,
            ]);
        }

        $data = $service->forFunnel(['pipeline_id' => $this->pipeline->id], VisibilityScope::Department, $head);

        // head + sub are in the dept subtree; the outsider is not.
        $this->assertSame(2, $data['in_work']);
    }

    public function test_all_scope_counts_every_deal(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);

        Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
            'company_id' => Company::factory()->create()->id,
        ]);
        $this->dealOn('new'); // director's own

        // Director resolves to All scope → both deals counted.
        $this->assertSame(2, $this->kpi()['in_work']);
    }

    // ------------------------------------------------------------------ list parity

    public function test_kpi_in_work_matches_a_direct_service_count(): void
    {
        // Belt-and-braces: the KPI in_work must equal what the same scoped base
        // query reports for distinct non-won companies, proving the chip reuses
        // the list's exact filter path (not a re-implementation).
        for ($i = 0; $i < 6; $i++) {
            $this->dealOn('new');
        }
        $this->dealOn('won');

        $deals = app(DealService::class);
        $base = $deals->kpiBaseQuery(['pipeline_id' => $this->pipeline->id], VisibilityScope::All, $this->director);

        $expected = (clone $base)
            ->whereHas('stage', static fn ($s) => $s->where('is_won', false))
            ->distinct()
            ->count('company_id');

        $this->assertSame($expected, $this->kpi()['in_work']);
        $this->assertSame(6, $expected);
    }
}
