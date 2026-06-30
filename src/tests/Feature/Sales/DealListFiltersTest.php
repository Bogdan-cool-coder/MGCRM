<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Models\Activity;
use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Full deal-list / board filter coverage (wiring audit): the funnel overlay
 * collapses ~10 dimensions onto canonical snake_case params and DealService must
 * apply every one of them — correctly, scope-safe, and without N+1. Each filter
 * gets a "narrows to the matching deal(s)" test; presets / status / product_q /
 * tags get their own; an N+1 guard caps the query count with all relation-backed
 * filters engaged.
 */
class DealListFiltersTest extends TestCase
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

    /** Convenience: a deal owned by the director on the given stage code + company. */
    private function dealOn(string $stageCode, ?Company $company = null, array $attrs = []): Deal
    {
        return Deal::factory()->forOwner($this->director)->create(array_merge([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, $stageCode),
            'company_id' => ($company ?? Company::factory()->create())->id,
        ], $attrs));
    }

    /** @return list<int> ids returned by GET /api/deals with the given query */
    private function listIds(string $query = ''): array
    {
        $response = $this->getJson('/api/deals'.$query)->assertOk();

        return array_map(static fn (array $row): int => (int) $row['id'], $response->json('data'));
    }

    // ---------------------------------------------------------------- owner_ids

    public function test_owner_ids_filters_to_those_owners(): void
    {
        $managerA = User::factory()->create(['role' => Role::Director]);
        $managerB = User::factory()->create(['role' => Role::Director]);

        $a = Deal::factory()->forOwner($managerA)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);
        $b = Deal::factory()->forOwner($managerB)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);
        $this->dealOn('new'); // director's own — must be excluded

        $ids = $this->listIds("?owner_ids[]={$managerA->id}&owner_ids[]={$managerB->id}");

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_owner_id_alias_still_works(): void
    {
        $manager = User::factory()->create(['role' => Role::Director]);
        $target = Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);
        $this->dealOn('new');

        $this->assertSame([$target->id], $this->listIds("?owner_id={$manager->id}"));
    }

    public function test_empty_owner_ids_array_is_ignored(): void
    {
        $this->dealOn('new');
        $this->dealOn('new');

        // owner_ids with no items must NOT collapse to whereIn([]) (zero rows).
        $this->assertCount(2, $this->listIds('?owner_ids='));
    }

    // ---------------------------------------------------------------- stage_ids

    public function test_stage_ids_filters_to_those_stages(): void
    {
        $newStage = $this->stageCode($this->pipeline, 'new');
        $qualify = $this->stageCode($this->pipeline, 'qualify');

        $a = $this->dealOn('new');
        $b = $this->dealOn('qualify');
        $this->dealOn('meeting'); // excluded

        $ids = $this->listIds("?stage_ids[]={$newStage}&stage_ids[]={$qualify}");

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    // ------------------------------------------------------------------- status

    public function test_status_won_returns_only_won_stage_deals(): void
    {
        $won = $this->dealOn('won');
        $this->dealOn('new');
        $this->dealOn('lost');

        $this->assertSame([$won->id], $this->listIds('?status=won'));
    }

    public function test_status_lost_returns_only_lost_stage_deals(): void
    {
        $lost = $this->dealOn('lost');
        $this->dealOn('new');
        $this->dealOn('won');

        $this->assertSame([$lost->id], $this->listIds('?status=lost'));
    }

    public function test_status_open_excludes_won_and_lost(): void
    {
        $open = $this->dealOn('new');
        $this->dealOn('won');
        $this->dealOn('lost');

        $this->assertSame([$open->id], $this->listIds('?status=open'));
    }

    // ---------------------------------------------------------------- only_mine

    public function test_only_mine_restricts_to_current_user(): void
    {
        // Director scope sees all, so only_mine must still narrow to own deals.
        $mine = $this->dealOn('new');
        $other = User::factory()->create(['role' => Role::Director]);
        Deal::factory()->forOwner($other)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);

        $this->assertSame([$mine->id], $this->listIds('?only_mine=true'));
    }

    // ------------------------------------------------------------- only_no_task

    public function test_only_no_task_returns_deals_without_open_task(): void
    {
        $withTask = $this->dealOn('new');
        Activity::factory()->task()->forDeal($withTask)
            ->create(['due_at' => now()->addDay(), 'is_closed' => false]);

        $withoutTask = $this->dealOn('new');

        // A completed (closed) task does not count — still "no open task".
        $withClosedTask = $this->dealOn('new');
        Activity::factory()->task()->forDeal($withClosedTask)->completed($this->director)
            ->create(['due_at' => now()->addDay(), 'is_closed' => true]);

        $ids = $this->listIds('?only_no_task=true');

        $this->assertEqualsCanonicalizing([$withoutTask->id, $withClosedTask->id], $ids);
    }

    // ------------------------------------------------------------- only_overdue

    public function test_only_overdue_returns_deals_with_overdue_task(): void
    {
        $overdue = $this->dealOn('new');
        Activity::factory()->task()->forDeal($overdue)->overdue()
            ->create(['due_at' => now()->subDays(2)]);

        $future = $this->dealOn('new');
        Activity::factory()->task()->forDeal($future)
            ->create(['due_at' => now()->addDays(3)]);

        $this->dealOn('new'); // no task at all — excluded

        $this->assertSame([$overdue->id], $this->listIds('?only_overdue=true'));
    }

    // -------------------------------------------------------------- product_q

    public function test_product_q_matches_line_item_product_name(): void
    {
        $widget = Product::factory()->create(['name' => 'Super Widget Pro']);
        $gadget = Product::factory()->create(['name' => 'Plain Gadget']);

        $matching = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $matching->id, 'product_id' => $widget->id]);

        $other = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $other->id, 'product_id' => $gadget->id]);

        $this->dealOn('new'); // no products — excluded

        $this->assertSame([$matching->id], $this->listIds('?product_q=Widget'));
    }

    // --------------------------------------------------------------- country

    public function test_country_filters_by_company_country_code(): void
    {
        $ru = $this->dealOn('new', Company::factory()->create(['country_code' => 'ru']));
        $this->dealOn('new', Company::factory()->create(['country_code' => 'kz']));

        $this->assertSame([$ru->id], $this->listIds('?country=ru'));
    }

    public function test_countries_multi_select_filters_to_any_of_the_codes(): void
    {
        $kz = $this->dealOn('new', Company::factory()->create(['country_code' => 'kz']));
        $uz = $this->dealOn('new', Company::factory()->create(['country_code' => 'uz']));
        $this->dealOn('new', Company::factory()->create(['country_code' => 'ru'])); // excluded

        $ids = $this->listIds('?countries[]=kz&countries[]=uz');

        $this->assertEqualsCanonicalizing([$kz->id, $uz->id], $ids);
    }

    public function test_single_country_still_works_alongside_multi(): void
    {
        $this->dealOn('new', Company::factory()->create(['country_code' => 'kz']));
        $this->dealOn('new', Company::factory()->create(['country_code' => 'uz']));
        $ru = $this->dealOn('new', Company::factory()->create(['country_code' => 'ru']));

        // Backward-compat: legacy single `country` param still narrows correctly.
        $this->assertSame([$ru->id], $this->listIds('?country=ru'));
    }

    public function test_empty_countries_array_is_ignored(): void
    {
        $this->dealOn('new', Company::factory()->create(['country_code' => 'kz']));
        $this->dealOn('new', Company::factory()->create(['country_code' => 'uz']));

        // countries with no items must NOT collapse to whereIn([]) (zero rows).
        $this->assertCount(2, $this->listIds('?countries='));
    }

    // ------------------------------------------------------------------ city

    public function test_city_filters_by_company_city(): void
    {
        $almaty = $this->dealOn('new', Company::factory()->create(['city' => 'Almaty']));
        $this->dealOn('new', Company::factory()->create(['city' => 'Astana']));

        $this->assertSame([$almaty->id], $this->listIds('?city=Almaty'));
    }

    // ----------------------------------------------- case-insensitive text search
    //
    // The three text filters (q→title, product_q→product name, city) use the
    // `whereLikeCi` macro (PG ILIKE / SQLite LOWER LIKE). Plain LIKE is
    // case-sensitive on PostgreSQL, so a lowercase fragment must still match a
    // capitalised value. ASCII proves the fold; same-case Cyrillic proves the
    // value flows through the pipeline unscathed (SQLite's LOWER() does not fold
    // Cyrillic without ICU, mirroring ContactSearchTest / ActivityTaskSearchTest).

    public function test_q_is_case_insensitive(): void
    {
        $match = $this->dealOn('new', null, ['title' => 'Enterprise Rollout']);
        $this->dealOn('new', null, ['title' => 'Small Pilot']);

        // lowercase fragment must match the capitalised title.
        $this->assertSame([$match->id], $this->listIds('?q=enterprise'));
    }

    public function test_city_is_case_insensitive(): void
    {
        $match = $this->dealOn('new', Company::factory()->create(['city' => 'Almaty']));
        $this->dealOn('new', Company::factory()->create(['city' => 'Astana']));

        $this->assertSame([$match->id], $this->listIds('?city=almaty'));
    }

    public function test_product_q_is_case_insensitive(): void
    {
        $widget = Product::factory()->create(['name' => 'Super Widget Pro']);
        $gadget = Product::factory()->create(['name' => 'Plain Gadget']);

        $match = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $match->id, 'product_id' => $widget->id]);

        $other = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $other->id, 'product_id' => $gadget->id]);

        // lowercase fragment must match the capitalised product name.
        $this->assertSame([$match->id], $this->listIds('?product_q=widget'));
    }

    public function test_q_matches_cyrillic_title(): void
    {
        $match = $this->dealOn('new', null, ['title' => 'Поставка оборудования']);
        $this->dealOn('new', null, ['title' => 'Аренда сервера']);

        // Same-case Cyrillic fragment — proves the value reaches the query intact.
        $this->assertSame([$match->id], $this->listIds('?q='.rawurlencode('Поставка')));
    }

    public function test_city_matches_cyrillic_city(): void
    {
        $match = $this->dealOn('new', Company::factory()->create(['city' => 'Москва']));
        $this->dealOn('new', Company::factory()->create(['city' => 'Казань']));

        $this->assertSame([$match->id], $this->listIds('?city='.rawurlencode('Москва')));
    }

    public function test_product_q_matches_cyrillic_product_name(): void
    {
        $valid = Product::factory()->create(['name' => 'Лицензия Профи']);
        $invalid = Product::factory()->create(['name' => 'Поддержка Базовая']);

        $match = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $match->id, 'product_id' => $valid->id]);

        $other = $this->dealOn('new');
        DealProduct::factory()->create(['deal_id' => $other->id, 'product_id' => $invalid->id]);

        $this->assertSame([$match->id], $this->listIds('?product_q='.rawurlencode('Лицензия')));
    }

    public function test_two_filters_intersect_with_and_logic(): void
    {
        // Text search + country must intersect (AND), not union.
        $ru = Company::factory()->create(['country_code' => 'ru', 'city' => 'Moscow']);
        $kz = Company::factory()->create(['country_code' => 'kz', 'city' => 'Moscow']);

        // Both deals match the text search; only the ru one also matches country.
        $target = $this->dealOn('new', $ru, ['title' => 'Pipeline Deal']);
        $this->dealOn('new', $kz, ['title' => 'Pipeline Deal']);

        // Third deal matches country but not the text search — also excluded.
        $this->dealOn('new', Company::factory()->create(['country_code' => 'ru']), ['title' => 'Other']);

        $this->assertSame([$target->id], $this->listIds('?q=pipeline&country=ru'));
    }

    // -------------------------------------------------------------- budget range

    public function test_budget_from_and_to_bound_the_amount(): void
    {
        // amount_locked so the explicit amount is not re-derived from products.
        $low = $this->dealOn('new', null, ['amount' => 50_000, 'amount_locked' => true]);
        $mid = $this->dealOn('new', null, ['amount' => 500_000, 'amount_locked' => true]);
        $high = $this->dealOn('new', null, ['amount' => 5_000_000, 'amount_locked' => true]);

        // [100_000 .. 1_000_000] kopecks → only the mid deal.
        $this->assertSame([$mid->id], $this->listIds('?budget_from=100000&budget_to=1000000'));
        // from only.
        $this->assertEqualsCanonicalizing([$mid->id, $high->id], $this->listIds('?budget_from=100000'));
        // to only.
        $this->assertEqualsCanonicalizing([$low->id, $mid->id], $this->listIds('?budget_to=1000000'));
    }

    /**
     * Reproduction of the production anomaly: filtering «Бюджет от 1 000 000 до
     * 3 509 000 ₽» wrongly surfaced a «34 483 ₽» deal. The FE sends both bounds
     * already converted to KOPECKS (rubles×100); deals.amount is kopecks. With the
     * range below ONLY the 2 000 000 ₽ deal must return — the 34 483 ₽ deal (far
     * below the lower bound) and the 5 000 000 ₽ deal (above the upper bound) are
     * both excluded. A pass here proves the logic is sound and the live anomaly is
     * a DATA issue (an amount row stored in rubles, not kopecks).
     */
    public function test_budget_range_in_kopecks_excludes_out_of_range_deals(): void
    {
        $tiny = $this->dealOn('new', null, ['amount' => 34_483_00, 'amount_locked' => true]);   // 34 483 ₽
        $inRange = $this->dealOn('new', null, ['amount' => 2_000_000_00, 'amount_locked' => true]); // 2 000 000 ₽
        $tooHigh = $this->dealOn('new', null, ['amount' => 5_000_000_00, 'amount_locked' => true]); // 5 000 000 ₽

        // от 1 000 000 ₽ до 3 509 000 ₽, both bounds in kopecks (rubles×100).
        $ids = $this->listIds('?budget_from=100000000&budget_to=350900000');

        $this->assertSame([$inRange->id], $ids);
        $this->assertNotContains($tiny->id, $ids, 'A deal far below the lower bound must be excluded.');
        $this->assertNotContains($tooHigh->id, $ids, 'A deal above the upper bound must be excluded.');
    }

    /**
     * A blank budget_to bound must be a no-op, NOT `amount <= 0` (which would empty
     * the result). Guards the defensive numericBound() skip.
     */
    public function test_empty_budget_bounds_are_ignored(): void
    {
        $a = $this->dealOn('new', null, ['amount' => 50_000, 'amount_locked' => true]);
        $b = $this->dealOn('new', null, ['amount' => 5_000_000, 'amount_locked' => true]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listIds('?budget_from=&budget_to='));
    }

    // ------------------------------------------------------------ created range

    public function test_created_from_and_to_bound_creation_date(): void
    {
        $old = $this->dealOn('new');
        $old->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $recent = $this->dealOn('new');
        $recent->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $fromDate = now()->subDays(5)->toDateString();

        $this->assertSame([$recent->id], $this->listIds("?created_from={$fromDate}"));

        $toDate = now()->subDays(5)->toDateString();
        $this->assertSame([$old->id], $this->listIds("?created_to={$toDate}"));
    }

    // ------------------------------------------------------------------- tags

    public function test_tags_match_any_of_the_requested_tags(): void
    {
        $vip = $this->dealOn('new', null, ['tags' => ['vip', 'enterprise']]);
        $urgent = $this->dealOn('new', null, ['tags' => ['urgent']]);
        $this->dealOn('new', null, ['tags' => ['cold']]); // excluded

        $ids = $this->listIds('?tags[]=vip&tags[]=urgent');

        $this->assertEqualsCanonicalizing([$vip->id, $urgent->id], $ids);
    }

    public function test_empty_tags_array_is_ignored(): void
    {
        $this->dealOn('new', null, ['tags' => ['x']]);
        $this->dealOn('new', null, ['tags' => []]);

        $this->assertCount(2, $this->listIds('?tags='));
    }

    // --------------------------------------------------------------- validation

    public function test_invalid_status_is_rejected(): void
    {
        $this->getJson('/api/deals?status=bogus')->assertStatus(422);
    }

    public function test_unknown_owner_id_is_rejected(): void
    {
        $this->getJson('/api/deals?owner_id=999999')->assertStatus(422);
    }

    // ------------------------------------------------------------ visibility

    public function test_scope_is_respected_alongside_filters(): void
    {
        // A manager (Own scope) must never see a foreign deal even when an
        // owner_ids filter explicitly names that foreign owner.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $foreignOwner = User::factory()->create(['role' => Role::Manager]);

        Deal::factory()->forOwner($foreignOwner)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);
        $mine = Deal::factory()->forOwner($manager)->create([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
        ]);

        Sanctum::actingAs($manager, ['*']);

        // Filter names BOTH owners; scope still hides the foreign one.
        $ids = $this->listIds("?owner_ids[]={$foreignOwner->id}&owner_ids[]={$manager->id}");

        $this->assertSame([$mine->id], $ids);
    }

    // ------------------------------------------------------------------- board

    public function test_board_honours_cross_cutting_filters(): void
    {
        $ru = Company::factory()->create(['country_code' => 'ru']);
        $kz = Company::factory()->create(['country_code' => 'kz']);

        $this->dealOn('new', $ru);
        $this->dealOn('qualify', $kz);

        $response = $this->getJson('/api/deals?view=board&country=ru')->assertOk();
        $columns = $response->json('columns');

        $newStageId = (string) $this->stageCode($this->pipeline, 'new');
        $qualifyStageId = (string) $this->stageCode($this->pipeline, 'qualify');

        // The ru deal sits in 'new'; the kz deal in 'qualify' is filtered out.
        $this->assertSame(1, $columns[$newStageId]['total']);
        $this->assertSame(0, $columns[$qualifyStageId]['total']);
    }

    // -------------------------------------------------------------- N+1 guard

    public function test_relation_backed_filters_do_not_n_plus_one(): void
    {
        $product = Product::factory()->create(['name' => 'Batched Product']);

        for ($i = 0; $i < 12; $i++) {
            $deal = $this->dealOn('new', Company::factory()->create([
                'country_code' => 'ru',
                'city' => 'Moscow',
            ]), ['tags' => ['batch']]);
            DealProduct::factory()->create(['deal_id' => $deal->id, 'product_id' => $product->id]);
            Activity::factory()->task()->forDeal($deal)->create(['due_at' => now()->addDay()]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/deals?per_page=25&country=ru&city=Moscow&product_q=Batched&tags[]=batch&only_overdue=false')
            ->assertOk()
            ->assertJsonCount(12, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // whereHas filters are single correlated subqueries (constant), the list
        // eager-loads relations and batches enrichment — the query count must be
        // flat regardless of row count, well under "1 + 12 rows".
        $this->assertLessThan(
            20,
            $count,
            "Relation-backed filters must not N+1 (ran {$count} queries).",
        );
    }
}
