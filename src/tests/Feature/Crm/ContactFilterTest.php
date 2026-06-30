<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for ContactService::list filter expansion.
 *
 * Covers:
 *   owner_ids[]        — multi-owner, scalar owner_id alias
 *   author_ids[]       — creator (created_by_id)
 *   sources[]          — multi-source, scalar source alias
 *   tags[]             — JSON any-match
 *   position           — confirmed working (partial match)
 *   created_from/to    — created_at window
 *   last_touch_from/to — last_activity_at window
 *   open_deals_min/max — subquery count of open deals
 *   only_mine          — preset: owned by auth user
 *   only_active        — preset: recent last_activity_at
 *   only_with_deals    — preset: has at least one deal
 *   only_no_task       — preset: no open task-like activity
 */
class ContactFilterTest extends TestCase
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
    // owner_ids[] — multi-owner filter
    // =========================================================================

    public function test_owner_ids_filters_single_owner(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $match = Contact::factory()->create(['owner_id' => $owner->id]);
        Contact::factory()->create(['owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?owner_ids[]='.$owner->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_owner_ids_filters_multiple_owners(): void
    {
        $o1 = User::factory()->create(['role' => Role::Manager]);
        $o2 = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $c1 = Contact::factory()->create(['owner_id' => $o1->id]);
        $c2 = Contact::factory()->create(['owner_id' => $o2->id]);
        Contact::factory()->create(['owner_id' => $other->id]);

        $response = $this->getJson("/api/contacts?owner_ids[]={$o1->id}&owner_ids[]={$o2->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_owner_id_alias_still_works(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $match = Contact::factory()->create(['owner_id' => $owner->id]);
        Contact::factory()->create(['owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?owner_id='.$owner->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // author_ids[] — created_by_id filter
    // =========================================================================

    public function test_author_ids_filters_by_creator(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $match = Contact::factory()->create(['created_by_id' => $author->id]);
        Contact::factory()->create(['created_by_id' => null]);

        $response = $this->getJson('/api/contacts?author_ids[]='.$author->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_author_ids_multi(): void
    {
        $a1 = User::factory()->create(['role' => Role::Manager]);
        $a2 = User::factory()->create(['role' => Role::Manager]);

        $c1 = Contact::factory()->create(['created_by_id' => $a1->id]);
        $c2 = Contact::factory()->create(['created_by_id' => $a2->id]);
        Contact::factory()->create(['created_by_id' => null]);

        $response = $this->getJson("/api/contacts?author_ids[]={$a1->id}&author_ids[]={$a2->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    // =========================================================================
    // sources[] — multi-source filter
    // =========================================================================

    public function test_sources_filters_single_source(): void
    {
        $match = Contact::factory()->create(['source' => 'referral']);
        Contact::factory()->create(['source' => 'cold_call']);

        $response = $this->getJson('/api/contacts?sources[]=referral')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_sources_filters_multiple_sources(): void
    {
        $c1 = Contact::factory()->create(['source' => 'referral']);
        $c2 = Contact::factory()->create(['source' => 'cold_call']);
        Contact::factory()->create(['source' => 'website']);

        $response = $this->getJson('/api/contacts?sources[]=referral&sources[]=cold_call')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_scalar_source_alias_still_works(): void
    {
        $match = Contact::factory()->create(['source' => 'referral']);
        Contact::factory()->create(['source' => 'cold_call']);

        $response = $this->getJson('/api/contacts?source=referral')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // tags[] — JSON any-match
    // =========================================================================

    public function test_tags_filter_any_match(): void
    {
        $match = Contact::factory()->create(['tags' => ['vip', 'partner']]);
        Contact::factory()->create(['tags' => ['cold']]);

        $response = $this->getJson('/api/contacts?tags[]=vip')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_tags_multi_any_match(): void
    {
        $c1 = Contact::factory()->create(['tags' => ['vip']]);
        $c2 = Contact::factory()->create(['tags' => ['partner']]);
        Contact::factory()->create(['tags' => ['cold']]);

        $response = $this->getJson('/api/contacts?tags[]=vip&tags[]=partner')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_empty_tags_array_ignored(): void
    {
        Contact::factory()->count(2)->create(['tags' => ['vip']]);

        // tags[] absent → returns all 2
        $response = $this->getJson('/api/contacts')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    // =========================================================================
    // position — partial match (already existed; confirm wiring)
    // =========================================================================

    public function test_position_partial_match(): void
    {
        $match = Contact::factory()->create(['position' => 'Директор по маркетингу']);
        Contact::factory()->create(['position' => 'Бухгалтер']);

        $response = $this->getJson('/api/contacts?position=Директор')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // created_from / created_to
    // =========================================================================

    public function test_created_from_filters_recent_contacts(): void
    {
        $old = Contact::factory()->create(['created_at' => Carbon::now()->subDays(10)]);
        $new = Contact::factory()->create(['created_at' => Carbon::now()->subDays(1)]);

        $cutoff = Carbon::now()->subDays(5)->toDateString();

        $response = $this->getJson("/api/contacts?created_from={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($new->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_created_to_filters_old_contacts(): void
    {
        $old = Contact::factory()->create(['created_at' => Carbon::now()->subDays(10)]);
        Contact::factory()->create(['created_at' => Carbon::now()->subDays(1)]);

        $cutoff = Carbon::now()->subDays(5)->toDateString();

        $response = $this->getJson("/api/contacts?created_to={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($old->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // last_touch_from / last_touch_to (last_activity_at)
    // =========================================================================

    public function test_last_touch_from_filters_by_recent_activity(): void
    {
        $fresh = Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(2)]);
        $stale = Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(20)]);

        $cutoff = Carbon::now()->subDays(7)->toDateString();

        $response = $this->getJson("/api/contacts?last_touch_from={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($stale->id, $ids);
    }

    public function test_last_touch_to_filters_by_old_activity(): void
    {
        Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(2)]);
        $stale = Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(20)]);

        $cutoff = Carbon::now()->subDays(7)->toDateString();

        $response = $this->getJson("/api/contacts?last_touch_to={$cutoff}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($stale->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // open_deals_min / open_deals_max
    // =========================================================================

    private function makeOpenDeal(Contact $contact): Deal
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'is_won' => false,
            'is_lost' => false,
            'sort_order' => 1,
        ]);
        $company = Company::factory()->create(['owner_user_id' => $this->admin->id]);
        $deal = Deal::factory()->inStage($stage)->create(['company_id' => $company->id]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        return $deal;
    }

    public function test_open_deals_min_filters_contacts_with_enough_open_deals(): void
    {
        $rich = Contact::factory()->create();
        $this->makeOpenDeal($rich);
        $this->makeOpenDeal($rich);

        $poor = Contact::factory()->create();
        $this->makeOpenDeal($poor);

        $none = Contact::factory()->create();

        $response = $this->getJson('/api/contacts?open_deals_min=2')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($rich->id, $ids);
        $this->assertNotContains($poor->id, $ids);
        $this->assertNotContains($none->id, $ids);
    }

    public function test_open_deals_max_filters_contacts_without_too_many_open_deals(): void
    {
        $rich = Contact::factory()->create();
        $this->makeOpenDeal($rich);
        $this->makeOpenDeal($rich);

        $poor = Contact::factory()->create();
        $this->makeOpenDeal($poor);

        $none = Contact::factory()->create();

        $response = $this->getJson('/api/contacts?open_deals_max=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($rich->id, $ids);
        $this->assertContains($poor->id, $ids);
        $this->assertContains($none->id, $ids);
    }

    public function test_open_deals_min_and_max_combined(): void
    {
        $rich = Contact::factory()->create();
        $this->makeOpenDeal($rich);
        $this->makeOpenDeal($rich);

        $mid = Contact::factory()->create();
        $this->makeOpenDeal($mid);

        $none = Contact::factory()->create();

        $response = $this->getJson('/api/contacts?open_deals_min=1&open_deals_max=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($mid->id, $ids);
        $this->assertNotContains($rich->id, $ids);
        $this->assertNotContains($none->id, $ids);
    }

    // =========================================================================
    // Presets: only_mine
    // =========================================================================

    public function test_only_mine_returns_contacts_owned_by_auth_user(): void
    {
        $mine = Contact::factory()->create(['owner_id' => $this->admin->id]);
        $other = User::factory()->create(['role' => Role::Manager]);
        Contact::factory()->create(['owner_id' => $other->id]);

        $response = $this->getJson('/api/contacts?only_mine=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // Presets: only_active
    // =========================================================================

    public function test_only_active_returns_recently_touched_contacts(): void
    {
        $fresh = Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(3)]);
        $stale = Contact::factory()->create(['last_activity_at' => Carbon::now()->subDays(60)]);
        Contact::factory()->create(['last_activity_at' => null]);

        $response = $this->getJson('/api/contacts?only_active=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($stale->id, $ids);
    }

    // =========================================================================
    // Presets: only_with_deals
    // =========================================================================

    public function test_only_with_deals_returns_contacts_with_deals(): void
    {
        $has = Contact::factory()->create();
        $this->makeOpenDeal($has);

        $no = Contact::factory()->create();

        $response = $this->getJson('/api/contacts?only_with_deals=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($has->id, $ids);
        $this->assertNotContains($no->id, $ids);
    }

    // =========================================================================
    // Presets: only_no_task
    // =========================================================================

    public function test_only_no_task_excludes_contacts_with_open_tasks(): void
    {
        $noTask = Contact::factory()->create();

        $hasTask = Contact::factory()->create();
        Activity::factory()->task()->forContact($hasTask)->create();

        $response = $this->getJson('/api/contacts?only_no_task=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($noTask->id, $ids);
        $this->assertNotContains($hasTask->id, $ids);
    }

    public function test_only_no_task_includes_contacts_with_only_closed_tasks(): void
    {
        $closedTask = Contact::factory()->create();
        Activity::factory()->task()->forContact($closedTask)->completed()->create();

        $response = $this->getJson('/api/contacts?only_no_task=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($closedTask->id, $ids);
    }

    // =========================================================================
    // BUG-2.2: author_ids filter — contacts where created_by_id IS NULL must
    // not appear when filtering by a specific author (CRM-2.2).
    // Also confirms AND-semantics when combining author + owner filters.
    // =========================================================================

    public function test_author_filter_does_not_return_contacts_with_null_created_by(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);

        // Two contacts: one with the author set, one with created_by_id=NULL (legacy).
        $withAuthor = Contact::factory()->create(['created_by_id' => $author->id]);
        Contact::factory()->create(['created_by_id' => null]);

        $response = $this->getJson('/api/contacts?author_ids[]='.$author->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($withAuthor->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_author_and_owner_combined_filters_use_and_logic(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        // Only this contact satisfies BOTH author AND owner conditions.
        $both = Contact::factory()->create([
            'created_by_id' => $author->id,
            'owner_id' => $owner->id,
        ]);
        // Created by author but owned by someone else.
        Contact::factory()->create([
            'created_by_id' => $author->id,
            'owner_id' => $other->id,
        ]);
        // Owned by owner but created by someone else.
        Contact::factory()->create([
            'created_by_id' => $other->id,
            'owner_id' => $owner->id,
        ]);

        $response = $this->getJson(
            "/api/contacts?author_ids[]={$author->id}&owner_ids[]={$owner->id}"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($both->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_source_and_owner_combined_filters_use_and_logic(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $both = Contact::factory()->create(['source' => 'referral', 'owner_id' => $owner->id]);
        Contact::factory()->create(['source' => 'referral', 'owner_id' => $other->id]);
        Contact::factory()->create(['source' => 'cold_call', 'owner_id' => $owner->id]);

        $response = $this->getJson(
            "/api/contacts?sources[]=referral&owner_ids[]={$owner->id}"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($both->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_tags_and_source_combined_filters_use_and_logic(): void
    {
        $both = Contact::factory()->create(['source' => 'referral', 'tags' => ['vip', 'partner']]);
        Contact::factory()->create(['source' => 'referral', 'tags' => ['cold']]);
        Contact::factory()->create(['source' => 'cold_call', 'tags' => ['vip']]);

        $response = $this->getJson('/api/contacts?sources[]=referral&tags[]=vip')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($both->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // D-4 backend: POST /contacts/{contact}/companies with is_primary
    // (already implemented — confirm via integration test)
    // =========================================================================

    public function test_store_link_with_is_primary_true_unsets_previous_primary(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $contact = Contact::factory()->create(['owner_id' => $manager->id]);
        $co1 = Company::factory()->create(['owner_user_id' => $manager->id]);
        $co2 = Company::factory()->create(['owner_user_id' => $manager->id]);

        // First link: co1 as primary
        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);

        // POST co2 as primary — should unset co1
        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $co2->id,
            'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co2->id,
            'is_primary' => true,
        ]);
    }

    public function test_store_link_without_is_primary_does_not_affect_existing_primary(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $contact = Contact::factory()->create(['owner_id' => $manager->id]);
        $co1 = Company::factory()->create(['owner_user_id' => $manager->id]);
        $co2 = Company::factory()->create(['owner_user_id' => $manager->id]);

        ContactCompanyLink::create([
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);

        // POST co2 without is_primary — co1 should stay primary
        $this->postJson("/api/contacts/{$contact->id}/companies", [
            'company_id' => $co2->id,
            'is_primary' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $co1->id,
            'is_primary' => true,
        ]);
    }
}
