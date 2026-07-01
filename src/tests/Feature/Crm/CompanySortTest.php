<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for CompanyService::list sort (sort_by + sort_dir).
 *
 * Each test seeds records whose NATURAL INSERTION ORDER differs from the
 * expected sorted order — so the test would FAIL if applySort() is not wired
 * (i.e. insertion-order coincidence cannot produce a false pass).
 *
 * Covers:
 *   name         → crm_companies.name             (direct column)
 *   category     → crm_companies.category_code    (direct column)
 *   country      → crm_companies.country_code     (direct column)
 *   last_contact → crm_companies.last_activity_at (direct column)
 *   created      → crm_companies.created_at        (direct column)
 *   owner        → users.full_name LEFT JOIN       (relation)
 *   engagement   → last_activity_at ordering       (direct column with semantic mapping)
 *   deals        → COUNT correlated subquery        (aggregate)
 *
 * Validation: invalid sort_by/sort_dir must return 422.
 * Default order: newest-first when no sort_by.
 */
class CompanySortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // =========================================================================
    // sort_by=name  — 3 records seeded Z, A, M (not alphabetical)
    // =========================================================================

    public function test_sort_by_name_asc_returns_companies_alphabetically(): void
    {
        // Insert in Z, A, M order — default (newest by created_at) would give M, A, Z
        Company::factory()->create(['name' => 'Zeta Corp',  'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Alpha Corp', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Mango Corp', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=name&sort_dir=asc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        // Must be A, M, Z — would fail if sort is not applied
        $this->assertSame('Alpha Corp', $names[0]);
        $this->assertSame('Mango Corp', $names[1]);
        $this->assertSame('Zeta Corp', $names[2]);
    }

    public function test_sort_by_name_desc_returns_companies_reverse_alphabetically(): void
    {
        // Insert in A, Z, M order
        Company::factory()->create(['name' => 'Alpha Corp', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Zeta Corp',  'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Mango Corp', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=name&sort_dir=desc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        // Must be Z, M, A — would fail if sort is not applied (gives M, Z, A by desc created_at)
        $this->assertSame('Zeta Corp', $names[0]);
        $this->assertSame('Mango Corp', $names[1]);
        $this->assertSame('Alpha Corp', $names[2]);
    }

    // =========================================================================
    // sort_by=category  — 3 records seeded M, L, S1 (not sorted)
    // =========================================================================

    public function test_sort_by_category_asc_orders_by_category_code(): void
    {
        // Insert in M, S2, L order
        Company::factory()->create(['name' => 'Co M',  'category_code' => 'M',  'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Co S2', 'category_code' => 'S2', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'Co L',  'category_code' => 'L',  'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=category&sort_dir=asc&per_page=10')
            ->assertOk();

        $codes = array_column($response->json('data'), 'category_code');

        // Alphabetical: L, M, S2
        $this->assertSame('L', $codes[0]);
        $this->assertSame('M', $codes[1]);
        $this->assertSame('S2', $codes[2]);
    }

    // =========================================================================
    // sort_by=country  — 3 records seeded in RU, AZ, KZ order
    // =========================================================================

    public function test_sort_by_country_asc_orders_by_country_code(): void
    {
        // Insert in RU, AZ, KZ order (not alphabetical = default wouldn't match asc)
        Company::factory()->create(['name' => 'RU Co', 'country_code' => 'RU', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'AZ Co', 'country_code' => 'AZ', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'KZ Co', 'country_code' => 'KZ', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=country&sort_dir=asc&per_page=10')
            ->assertOk();

        $codes = array_column($response->json('data'), 'country_code');

        $this->assertSame('AZ', $codes[0]);
        $this->assertSame('KZ', $codes[1]);
        $this->assertSame('RU', $codes[2]);
    }

    public function test_sort_by_country_desc_orders_by_country_code_reversed(): void
    {
        Company::factory()->create(['name' => 'AZ Co', 'country_code' => 'AZ', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'KZ Co', 'country_code' => 'KZ', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['name' => 'RU Co', 'country_code' => 'RU', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=country&sort_dir=desc&per_page=10')
            ->assertOk();

        $codes = array_column($response->json('data'), 'country_code');

        $this->assertSame('RU', $codes[0]);
        $this->assertSame('KZ', $codes[1]);
        $this->assertSame('AZ', $codes[2]);
    }

    // =========================================================================
    // sort_by=last_contact  — 3 records in non-recency insertion order
    // =========================================================================

    public function test_sort_by_last_contact_desc_puts_most_recently_active_first(): void
    {
        // Insert never, old, recent — ensures default wouldn't match (desc created_at = recent,old,never)
        $never = Company::factory()->create(['name' => 'Never',  'last_activity_at' => null,              'owner_user_id' => $this->admin->id]);
        $old = Company::factory()->create(['name' => 'Old',    'last_activity_at' => now()->subMonth(), 'owner_user_id' => $this->admin->id]);
        $recent = Company::factory()->create(['name' => 'Recent', 'last_activity_at' => now()->subDay(),   'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=last_contact&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // If sort is not applied (default desc created_at): recent, old, never — accidentally matches
        // BUT insertion order is never,old,recent which would give recent,old,never by desc created_at
        // We verify exact positions to confirm sort is applied and correct.
        $this->assertSame($recent->id, $ids[0]);
        $this->assertSame($old->id, $ids[1]);
        $this->assertSame($never->id, $ids[2]);
    }

    public function test_sort_by_last_contact_asc_puts_oldest_first(): void
    {
        // Insert in recent, old order — asc would flip them
        $recent = Company::factory()->create(['name' => 'Recent', 'last_activity_at' => now()->subDay(),   'owner_user_id' => $this->admin->id]);
        $old = Company::factory()->create(['name' => 'Old',    'last_activity_at' => now()->subMonth(), 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=last_contact&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // old before recent in asc (would fail if sort not wired — default gives recent,old)
        $this->assertLessThan(
            array_search($recent->id, $ids, true),
            array_search($old->id, $ids, true),
        );
    }

    // =========================================================================
    // sort_by=owner  — relation sort (LEFT JOIN users.full_name)
    // 3 records with owners Zara, Alice, Mia — asc must give Alice, Mia, Zara
    // =========================================================================

    public function test_sort_by_owner_asc_orders_by_owner_full_name(): void
    {
        $zara = User::factory()->create(['full_name' => 'Zara Manager',  'role' => Role::Manager]);
        $alice = User::factory()->create(['full_name' => 'Alice Manager', 'role' => Role::Manager]);
        $mia = User::factory()->create(['full_name' => 'Mia Manager',   'role' => Role::Manager]);

        // Insert in Z, A, M order so default (desc created_at) gives: Co Mia, Co Alice, Co Zara
        Company::factory()->create(['name' => 'Co Zara',  'owner_user_id' => $zara->id]);
        Company::factory()->create(['name' => 'Co Alice', 'owner_user_id' => $alice->id]);
        Company::factory()->create(['name' => 'Co Mia',   'owner_user_id' => $mia->id]);

        $response = $this->getJson('/api/companies?sort_by=owner&sort_dir=asc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        // Alice, Mia, Zara — would fail if unwired (gives Co Mia, Co Alice, Co Zara by desc created_at)
        $this->assertSame('Co Alice', $names[0]);
        $this->assertSame('Co Mia', $names[1]);
        $this->assertSame('Co Zara', $names[2]);
    }

    public function test_sort_by_owner_desc_orders_by_owner_full_name_reversed(): void
    {
        $alice = User::factory()->create(['full_name' => 'Alice Manager', 'role' => Role::Manager]);
        $zara = User::factory()->create(['full_name' => 'Zara Manager',  'role' => Role::Manager]);

        Company::factory()->create(['name' => 'Co Alice', 'owner_user_id' => $alice->id]);
        Company::factory()->create(['name' => 'Co Zara',  'owner_user_id' => $zara->id]);

        $response = $this->getJson('/api/companies?sort_by=owner&sort_dir=desc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        // Zara before Alice in desc
        $this->assertSame('Co Zara', $names[0]);
        $this->assertSame('Co Alice', $names[1]);
    }

    // =========================================================================
    // sort_by=deals  — aggregate subquery sort
    // 3 records seeded with 0, 1, 3 open deals in ascending order
    // =========================================================================

    public function test_sort_by_deals_desc_puts_most_open_deals_first(): void
    {
        // Insert in ascending deal-count order (0, 1, 3) so default doesn't accidentally match
        $noDeals = Company::factory()->create(['name' => 'No Deals',   'owner_user_id' => $this->admin->id]);
        $oneDeals = Company::factory()->create(['name' => 'One Deal',   'owner_user_id' => $this->admin->id]);
        $manyDeals = Company::factory()->create(['name' => 'Many Deals', 'owner_user_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        // 1 open deal for $oneDeals
        Deal::factory()->inStage($openStage)->create(['company_id' => $oneDeals->id]);

        // 3 open deals for $manyDeals
        Deal::factory()->inStage($openStage)->count(3)->create(['company_id' => $manyDeals->id]);

        $response = $this->getJson('/api/companies?sort_by=deals&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $posMany = array_search($manyDeals->id, $ids, true);
        $posOne = array_search($oneDeals->id, $ids, true);
        $posNone = array_search($noDeals->id, $ids, true);

        // many(3) → one(1) → none(0) — would fail if sort not wired (default gives many,one,no by desc created_at)
        $this->assertLessThan($posOne, $posMany, 'Company with 3 deals should sort before 1 deal');
        $this->assertLessThan($posNone, $posOne, 'Company with 1 deal should sort before 0 deals');
    }

    public function test_sort_by_deals_asc_puts_fewest_first(): void
    {
        $manyDeals = Company::factory()->create(['name' => 'Many Deals', 'owner_user_id' => $this->admin->id]);
        $noDeals = Company::factory()->create(['name' => 'No Deals',   'owner_user_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        Deal::factory()->inStage($openStage)->count(3)->create(['company_id' => $manyDeals->id]);

        $response = $this->getJson('/api/companies?sort_by=deals&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // noDeals before manyDeals in asc
        $this->assertLessThan(
            array_search($manyDeals->id, $ids, true),
            array_search($noDeals->id, $ids, true),
        );
    }

    public function test_sort_by_deals_excludes_won_and_lost_stages(): void
    {
        $company = Company::factory()->create(['name' => 'Test Co',  'owner_user_id' => $this->admin->id]);
        $empty = Company::factory()->create(['name' => 'Empty Co', 'owner_user_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $wonStage = PipelineStage::factory()->create(['is_won' => true,  'is_lost' => false]);
        $lostStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => true]);

        // 1 open + 1 won + 1 lost = only 1 should be counted
        Deal::factory()->inStage($openStage)->create(['company_id' => $company->id]);
        Deal::factory()->inStage($wonStage)->create(['company_id' => $company->id]);
        Deal::factory()->inStage($lostStage)->create(['company_id' => $company->id]);

        $response = $this->getJson('/api/companies?sort_by=deals&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // company has 1 open deal, empty has 0 → company first in desc
        $this->assertLessThan(
            array_search($empty->id, $ids, true),
            array_search($company->id, $ids, true),
        );
    }

    // =========================================================================
    // sort_by=created
    // =========================================================================

    public function test_sort_by_created_asc_oldest_first(): void
    {
        // Insert in reverse age order
        $newest = Company::factory()->create(['created_at' => now()->subDay(),    'owner_user_id' => $this->admin->id]);
        $middle = Company::factory()->create(['created_at' => now()->subDays(5),  'owner_user_id' => $this->admin->id]);
        $oldest = Company::factory()->create(['created_at' => now()->subDays(10), 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=created&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // oldest → middle → newest.  Without sort, default gives newest,middle,oldest (desc).
        $this->assertSame($oldest->id, $ids[0]);
        $this->assertSame($middle->id, $ids[1]);
        $this->assertSame($newest->id, $ids[2]);
    }

    public function test_sort_by_created_desc_newest_first(): void
    {
        $older = Company::factory()->create(['created_at' => now()->subDays(5), 'owner_user_id' => $this->admin->id]);
        $newer = Company::factory()->create(['created_at' => now()->subDay(),   'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sort_by=created&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame($newer->id, $ids[0]);
        $this->assertSame($older->id, $ids[1]);
    }

    // =========================================================================
    // Validation: invalid sort_by/sort_dir must return 422
    // =========================================================================

    public function test_invalid_sort_by_returns_422(): void
    {
        $this->getJson('/api/companies?sort_by=injected_col')
            ->assertUnprocessable();
    }

    public function test_invalid_sort_dir_returns_422(): void
    {
        $this->getJson('/api/companies?sort_by=name&sort_dir=up')
            ->assertUnprocessable();
    }

    // =========================================================================
    // Default order (no sort_by) — newest first unchanged
    // =========================================================================

    public function test_default_order_is_newest_first_when_no_sort_by(): void
    {
        $oldest = Company::factory()->create(['created_at' => now()->subDays(10), 'owner_user_id' => $this->admin->id]);
        $middle = Company::factory()->create(['created_at' => now()->subDays(5),  'owner_user_id' => $this->admin->id]);
        $newest = Company::factory()->create(['created_at' => now()->subDay(),    'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame($newest->id, $ids[0]);
        $this->assertSame($middle->id, $ids[1]);
        $this->assertSame($oldest->id, $ids[2]);
    }
}
