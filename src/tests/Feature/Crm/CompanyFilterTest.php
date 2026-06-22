<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for CompanyService::list filter expansion.
 *
 * Covers:
 *   owner_ids[]          — multi-owner (scalar owner_user_id alias)
 *   company_type_ids[]   — multi (scalar company_type_id alias)
 *   category_code[]      — multi L/M/S1/S2
 *   tags[]               — JSON any-match
 *   city                 — partial match
 *   country_code         — exact match (already existed; confirmed)
 *   sources[]            — multi-source (scalar source alias)
 *   created_from/to      — created_at window
 *   only_mine            — preset: owned by auth user
 *   only_active          — preset: recent last_activity_at
 *   only_with_deals      — preset: has at least one deal
 *   only_no_task         — preset: no open task-like activity
 */
class CompanyFilterTest extends TestCase
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
    // owner_ids[] — multi-owner filter (owner_user_id alias)
    // =========================================================================

    public function test_owner_ids_filters_single_owner(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $match = Company::factory()->create(['owner_user_id' => $owner->id]);
        Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?owner_ids[]='.$owner->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_owner_ids_filters_multiple_owners(): void
    {
        $o1 = User::factory()->create(['role' => Role::Manager]);
        $o2 = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $c1 = Company::factory()->create(['owner_user_id' => $o1->id]);
        $c2 = Company::factory()->create(['owner_user_id' => $o2->id]);
        Company::factory()->create(['owner_user_id' => $other->id]);

        $response = $this->getJson("/api/companies?owner_ids[]={$o1->id}&owner_ids[]={$o2->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_owner_user_id_alias_still_works(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $match = Company::factory()->create(['owner_user_id' => $owner->id]);
        Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?owner_user_id='.$owner->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // company_type_ids[] — multi (scalar company_type_id alias)
    // =========================================================================

    public function test_company_type_ids_single(): void
    {
        $type = CompanyType::firstOrCreate(['name' => 'ТОО'], ['sort_order' => 1, 'is_active' => true]);
        $other = CompanyType::firstOrCreate(['name' => 'АО'], ['sort_order' => 2, 'is_active' => true]);

        $match = Company::factory()->create(['company_type_id' => $type->id, 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['company_type_id' => $other->id, 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?company_type_ids[]='.$type->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_company_type_ids_multi(): void
    {
        $t1 = CompanyType::firstOrCreate(['name' => 'ТОО'], ['sort_order' => 1, 'is_active' => true]);
        $t2 = CompanyType::firstOrCreate(['name' => 'АО'], ['sort_order' => 2, 'is_active' => true]);
        $t3 = CompanyType::firstOrCreate(['name' => 'ИП'], ['sort_order' => 3, 'is_active' => true]);

        $c1 = Company::factory()->create(['company_type_id' => $t1->id, 'owner_user_id' => $this->admin->id]);
        $c2 = Company::factory()->create(['company_type_id' => $t2->id, 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['company_type_id' => $t3->id, 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson("/api/companies?company_type_ids[]={$t1->id}&company_type_ids[]={$t2->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_company_type_id_alias_still_works(): void
    {
        $type = CompanyType::firstOrCreate(['name' => 'ТОО'], ['sort_order' => 1, 'is_active' => true]);
        $match = Company::factory()->create(['company_type_id' => $type->id, 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['company_type_id' => null, 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?company_type_id='.$type->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // category_code[] — multi L/M/S1/S2
    // =========================================================================

    public function test_category_code_single_value(): void
    {
        $match = Company::factory()->create(['category_code' => 'L', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['category_code' => 'S1', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?category_code[]=L')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_category_code_multi_value(): void
    {
        $c1 = Company::factory()->create(['category_code' => 'L', 'owner_user_id' => $this->admin->id]);
        $c2 = Company::factory()->create(['category_code' => 'M', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['category_code' => 'S1', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?category_code[]=L&category_code[]=M')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_category_code_alias_still_works(): void
    {
        $match = Company::factory()->create(['category_code' => 'L', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['category_code' => 'S2', 'owner_user_id' => $this->admin->id]);

        // Scalar 'category_code' (not an array) — resolveStrings picks it up
        $response = $this->getJson('/api/companies?category_code=L')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // tags[]
    // =========================================================================

    public function test_tags_filter_any_match(): void
    {
        $match = Company::factory()->create(['tags' => ['key_account', 'vip'], 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['tags' => ['cold'], 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?tags[]=vip')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_tags_multi_any_match(): void
    {
        $c1 = Company::factory()->create(['tags' => ['key_account'], 'owner_user_id' => $this->admin->id]);
        $c2 = Company::factory()->create(['tags' => ['vip'], 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['tags' => ['cold'], 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?tags[]=key_account&tags[]=vip')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    // =========================================================================
    // city — partial match
    // =========================================================================

    public function test_city_partial_match(): void
    {
        $match = Company::factory()->create(['city' => 'Алматы', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['city' => 'Астана', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?city=лмат')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // sources[] — multi-source (scalar source alias)
    // =========================================================================

    public function test_sources_multi(): void
    {
        $c1 = Company::factory()->create(['source' => 'referral', 'owner_user_id' => $this->admin->id]);
        $c2 = Company::factory()->create(['source' => 'cold_call', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['source' => 'website', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?sources[]=referral&sources[]=cold_call')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_source_alias(): void
    {
        $match = Company::factory()->create(['source' => 'referral', 'owner_user_id' => $this->admin->id]);
        Company::factory()->create(['source' => 'website', 'owner_user_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?source=referral')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // created_from / created_to
    // =========================================================================

    public function test_created_from_filters_recent_companies(): void
    {
        $old = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $new = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        $cutoff = Carbon::now()->subDays(5)->toDateString();

        $response = $this->getJson("/api/companies?created_from={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($new->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_created_to_filters_old_companies(): void
    {
        $old = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'created_at' => Carbon::now()->subDays(10),
        ]);
        Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        $cutoff = Carbon::now()->subDays(5)->toDateString();

        $response = $this->getJson("/api/companies?created_to={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($old->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // Presets: only_mine
    // =========================================================================

    public function test_only_mine_returns_companies_owned_by_auth_user(): void
    {
        $mine = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $other = User::factory()->create(['role' => Role::Manager]);
        Company::factory()->create(['owner_user_id' => $other->id]);

        $response = $this->getJson('/api/companies?only_mine=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // Presets: only_active
    // =========================================================================

    public function test_only_active_returns_recently_touched_companies(): void
    {
        $fresh = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'last_activity_at' => Carbon::now()->subDays(3),
        ]);
        $stale = Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'last_activity_at' => Carbon::now()->subDays(120),
        ]);
        Company::factory()->create([
            'owner_user_id' => $this->admin->id,
            'last_activity_at' => null,
        ]);

        $response = $this->getJson('/api/companies?only_active=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($stale->id, $ids);
    }

    // =========================================================================
    // Presets: only_with_deals
    // =========================================================================

    public function test_only_with_deals_returns_companies_with_deals(): void
    {
        $has = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $no = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        // Create an open deal for $has
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        Deal::factory()->inStage($stage)->create(['company_id' => $has->id]);

        $response = $this->getJson('/api/companies?only_with_deals=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($has->id, $ids);
        $this->assertNotContains($no->id, $ids);
    }

    // =========================================================================
    // Presets: only_no_task
    // =========================================================================

    public function test_only_no_task_excludes_companies_with_open_tasks(): void
    {
        $noTask = Company::factory()->create(['owner_user_id' => $this->admin->id]);

        $hasTask = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        Activity::factory()->task()->forCompany($hasTask)->create();

        $response = $this->getJson('/api/companies?only_no_task=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($noTask->id, $ids);
        $this->assertNotContains($hasTask->id, $ids);
    }

    public function test_only_no_task_includes_companies_with_only_closed_tasks(): void
    {
        $closedTask = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        Activity::factory()->task()->forCompany($closedTask)->completed()->create();

        $response = $this->getJson('/api/companies?only_no_task=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($closedTask->id, $ids);
    }

    // =========================================================================
    // Empty / no-op filter values — must return all
    // =========================================================================

    public function test_empty_filter_params_return_all(): void
    {
        Company::factory()->count(3)->create(['owner_user_id' => $this->admin->id]);

        // Sending empty arrays — should be ignored
        $response = $this->getJson('/api/companies?owner_ids[]=&tags[]=&sources[]=')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }
}
