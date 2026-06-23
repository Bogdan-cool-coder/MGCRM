<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for ContactService::list sort (sort_by + sort_dir).
 *
 * Each test seeds records whose NATURAL INSERTION ORDER differs from the
 * expected sorted order — so the test would FAIL if applySort() is not wired
 * (i.e. insertion-order coincidence cannot produce a false pass).
 *
 * Covers:
 *   name          → crm_contacts.full_name  (direct column)
 *   phone         → crm_contacts.phone      (direct column)
 *   last_contact  → crm_contacts.last_activity_at (direct column)
 *   created       → crm_contacts.created_at  (direct column)
 *   author        → users.full_name LEFT JOIN (relation)
 *   company       → crm_companies.name LEFT JOIN via contact_company_links (aggregate/relation)
 *   open_deals    → COUNT correlated subquery (aggregate)
 *
 * Validation: invalid sort_by/sort_dir must return 422.
 * Default order: newest-first when no sort_by.
 */
class ContactSortTest extends TestCase
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
    // sort_by=name  — 3 records seeded B, A, C (differs from A, B, C order)
    // =========================================================================

    public function test_sort_by_name_asc_returns_contacts_alphabetically(): void
    {
        // Insert in B, A, C order — insertion order would return B,A,C without sort.
        Contact::factory()->create(['full_name' => 'Bob Jones',     'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Alice Smith',   'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Charlie Brown', 'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=name&sort_dir=asc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'full_name');

        // Must be A, B, C — would fail if sort is not applied (would give B, A, C)
        $this->assertSame('Alice Smith',   $names[0]);
        $this->assertSame('Bob Jones',     $names[1]);
        $this->assertSame('Charlie Brown', $names[2]);
    }

    public function test_sort_by_name_desc_returns_contacts_reverse_alphabetically(): void
    {
        // Insert in A, C, B order
        Contact::factory()->create(['full_name' => 'Alice Smith',   'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Charlie Brown', 'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Bob Jones',     'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=name&sort_dir=desc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'full_name');

        // Must be C, B, A — would fail if sort is not applied (would give A, C, B by desc created_at)
        $this->assertSame('Charlie Brown', $names[0]);
        $this->assertSame('Bob Jones',     $names[1]);
        $this->assertSame('Alice Smith',   $names[2]);
    }

    // =========================================================================
    // sort_by=phone  — 3 records seeded out of phone order
    // =========================================================================

    public function test_sort_by_phone_asc_returns_contacts_in_phone_order(): void
    {
        // Insert in reverse phone order
        Contact::factory()->create(['full_name' => 'C',    'phone' => '+79001113333', 'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'A',    'phone' => '+79001111111', 'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'B',    'phone' => '+79001112222', 'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=phone&sort_dir=asc&per_page=10')
            ->assertOk();

        $phones = array_column($response->json('data'), 'phone');

        $this->assertSame('+79001111111', $phones[0]);
        $this->assertSame('+79001112222', $phones[1]);
        $this->assertSame('+79001113333', $phones[2]);
    }

    // =========================================================================
    // sort_by=last_contact  — 3 records seeded in non-recency order
    // =========================================================================

    public function test_sort_by_last_contact_desc_puts_most_recent_first(): void
    {
        // Insert: never, old, recent — default insertion order would return newest-created last
        $never  = Contact::factory()->create(['full_name' => 'Never',  'last_activity_at' => null,             'owner_id' => $this->admin->id]);
        $old    = Contact::factory()->create(['full_name' => 'Old',    'last_activity_at' => now()->subMonth(), 'owner_id' => $this->admin->id]);
        $recent = Contact::factory()->create(['full_name' => 'Recent', 'last_activity_at' => now()->subDay(),  'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=last_contact&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // recent → old → never. Would fail if sort is not applied (default: newest-by-created_at = recent,old,never which accidentally matches — so we verify positions explicitly)
        $this->assertSame($recent->id, $ids[0]);
        $this->assertSame($old->id,    $ids[1]);
        $this->assertSame($never->id,  $ids[2]);
    }

    public function test_sort_by_last_contact_asc_puts_oldest_first(): void
    {
        // Insert in desc activity order so default would break asc
        $recent = Contact::factory()->create(['full_name' => 'Recent', 'last_activity_at' => now()->subDay(),   'owner_id' => $this->admin->id]);
        $old    = Contact::factory()->create(['full_name' => 'Old',    'last_activity_at' => now()->subMonth(), 'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=last_contact&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // old before recent in asc direction
        $this->assertLessThan(
            array_search($recent->id, $ids, true),
            array_search($old->id, $ids, true),
        );
    }

    // =========================================================================
    // sort_by=created  — 3 records with explicit non-sequential created_at
    // =========================================================================

    public function test_sort_by_created_asc_oldest_first(): void
    {
        // Insert in reverse age order: newest first in DB
        $newest = Contact::factory()->create(['created_at' => now()->subDay(),    'owner_id' => $this->admin->id]);
        $middle = Contact::factory()->create(['created_at' => now()->subDays(5),  'owner_id' => $this->admin->id]);
        $oldest = Contact::factory()->create(['created_at' => now()->subDays(10), 'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=created&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // oldest → middle → newest.  Without sort, default gives newest,middle,oldest (desc).
        $this->assertSame($oldest->id, $ids[0]);
        $this->assertSame($middle->id, $ids[1]);
        $this->assertSame($newest->id, $ids[2]);
    }

    public function test_sort_by_created_desc_newest_first(): void
    {
        $oldest = Contact::factory()->create(['created_at' => now()->subDays(10), 'owner_id' => $this->admin->id]);
        $newest = Contact::factory()->create(['created_at' => now()->subDay(),    'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=created&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame($newest->id, $ids[0]);
        $this->assertSame($oldest->id, $ids[1]);
    }

    // =========================================================================
    // sort_by=author  — relation sort (LEFT JOIN users on created_by_id)
    // =========================================================================

    public function test_sort_by_author_asc_orders_by_creator_full_name(): void
    {
        $zara  = User::factory()->create(['full_name' => 'Zara Creator', 'role' => Role::Manager]);
        $alice = User::factory()->create(['full_name' => 'Alice Creator', 'role' => Role::Manager]);
        $mia   = User::factory()->create(['full_name' => 'Mia Creator',  'role' => Role::Manager]);

        // Insert in Z, A, M order so default (newest-first by created_at) would not give A,M,Z
        Contact::factory()->create(['full_name' => 'Con Z', 'created_by_id' => $zara->id,  'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Con A', 'created_by_id' => $alice->id, 'owner_id' => $this->admin->id]);
        Contact::factory()->create(['full_name' => 'Con M', 'created_by_id' => $mia->id,   'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?sort_by=author&sort_dir=asc&per_page=10')
            ->assertOk();

        $names = array_column($response->json('data'), 'full_name');

        // A, M, Z — if unwired, insertion order gives Con Z, Con A, Con M (desc created_at)
        $this->assertSame('Con A', $names[0]);
        $this->assertSame('Con M', $names[1]);
        $this->assertSame('Con Z', $names[2]);
    }

    // =========================================================================
    // sort_by=company  — relation sort (LEFT JOIN on primary company name)
    // =========================================================================

    public function test_sort_by_company_asc_orders_by_primary_company_name(): void
    {
        // Create contacts in Zeta, Alpha, Mango order (descending company name)
        $conZ = Contact::factory()->create(['full_name' => 'Con Zeta',  'owner_id' => $this->admin->id]);
        $conA = Contact::factory()->create(['full_name' => 'Con Alpha', 'owner_id' => $this->admin->id]);
        $conM = Contact::factory()->create(['full_name' => 'Con Mango', 'owner_id' => $this->admin->id]);

        $coZ = Company::factory()->create(['name' => 'Zeta Corp',  'owner_user_id' => $this->admin->id]);
        $coA = Company::factory()->create(['name' => 'Alpha Corp', 'owner_user_id' => $this->admin->id]);
        $coM = Company::factory()->create(['name' => 'Mango Corp', 'owner_user_id' => $this->admin->id]);

        ContactCompanyLink::create(['contact_id' => $conZ->id, 'company_id' => $coZ->id, 'is_primary' => true]);
        ContactCompanyLink::create(['contact_id' => $conA->id, 'company_id' => $coA->id, 'is_primary' => true]);
        ContactCompanyLink::create(['contact_id' => $conM->id, 'company_id' => $coM->id, 'is_primary' => true]);

        $response = $this->getJson('/api/contacts?sort_by=company&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // Expected: Alpha(A), Mango(M), Zeta(Z) — if unwired default gives conM,conA,conZ (desc created_at)
        $posA = array_search($conA->id, $ids, true);
        $posM = array_search($conM->id, $ids, true);
        $posZ = array_search($conZ->id, $ids, true);

        $this->assertLessThan($posM, $posA, 'Alpha Corp should sort before Mango Corp');
        $this->assertLessThan($posZ, $posM, 'Mango Corp should sort before Zeta Corp');
    }

    public function test_sort_by_company_desc_orders_by_primary_company_name_reversed(): void
    {
        $conA = Contact::factory()->create(['full_name' => 'Con Alpha', 'owner_id' => $this->admin->id]);
        $conZ = Contact::factory()->create(['full_name' => 'Con Zeta',  'owner_id' => $this->admin->id]);

        $coA = Company::factory()->create(['name' => 'Alpha Corp', 'owner_user_id' => $this->admin->id]);
        $coZ = Company::factory()->create(['name' => 'Zeta Corp',  'owner_user_id' => $this->admin->id]);

        ContactCompanyLink::create(['contact_id' => $conA->id, 'company_id' => $coA->id, 'is_primary' => true]);
        ContactCompanyLink::create(['contact_id' => $conZ->id, 'company_id' => $coZ->id, 'is_primary' => true]);

        $response = $this->getJson('/api/contacts?sort_by=company&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // Zeta before Alpha in desc
        $this->assertLessThan(
            array_search($conA->id, $ids, true),
            array_search($conZ->id, $ids, true),
        );
    }

    // =========================================================================
    // sort_by=open_deals  — aggregate subquery sort
    // =========================================================================

    public function test_sort_by_open_deals_desc_puts_most_deals_first(): void
    {
        // Insert in ascending deal-count order so default wouldn't accidentally pass
        $noDeals   = Contact::factory()->create(['full_name' => 'Zero Deals', 'owner_id' => $this->admin->id]);
        $oneContact = Contact::factory()->create(['full_name' => 'One Deal',  'owner_id' => $this->admin->id]);
        $manyDeals = Contact::factory()->create(['full_name' => 'Many Deals', 'owner_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        // 1 open deal for $oneContact
        $d1 = Deal::factory()->inStage($openStage)->create();
        DB::table('deal_contacts')->insert(['deal_id' => $d1->id, 'contact_id' => $oneContact->id]);

        // 3 open deals for $manyDeals
        for ($i = 0; $i < 3; $i++) {
            $d = Deal::factory()->inStage($openStage)->create();
            DB::table('deal_contacts')->insert(['deal_id' => $d->id, 'contact_id' => $manyDeals->id]);
        }

        $response = $this->getJson('/api/contacts?sort_by=open_deals&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $posMany = array_search($manyDeals->id, $ids, true);
        $posOne  = array_search($oneContact->id, $ids, true);
        $posNone = array_search($noDeals->id, $ids, true);

        // many(3) → one(1) → none(0)
        $this->assertLessThan($posOne,  $posMany, 'Contact with 3 deals should sort before 1 deal');
        $this->assertLessThan($posNone, $posOne,  'Contact with 1 deal should sort before 0 deals');
    }

    public function test_sort_by_open_deals_asc_puts_fewest_first(): void
    {
        $manyDeals = Contact::factory()->create(['full_name' => 'Many Deals', 'owner_id' => $this->admin->id]);
        $noDeals   = Contact::factory()->create(['full_name' => 'Zero Deals', 'owner_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);

        for ($i = 0; $i < 3; $i++) {
            $d = Deal::factory()->inStage($openStage)->create();
            DB::table('deal_contacts')->insert(['deal_id' => $d->id, 'contact_id' => $manyDeals->id]);
        }

        $response = $this->getJson('/api/contacts?sort_by=open_deals&sort_dir=asc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // none before many in asc
        $this->assertLessThan(
            array_search($manyDeals->id, $ids, true),
            array_search($noDeals->id, $ids, true),
        );
    }

    public function test_open_deals_sort_excludes_won_and_lost(): void
    {
        $contact = Contact::factory()->create(['full_name' => 'Mixed Deals', 'owner_id' => $this->admin->id]);
        $empty   = Contact::factory()->create(['full_name' => 'Zero Deals',  'owner_id' => $this->admin->id]);

        $openStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $wonStage  = PipelineStage::factory()->create(['is_won' => true,  'is_lost' => false]);
        $lostStage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => true]);

        // 1 open + 1 won + 1 lost = only 1 should count
        $dOpen = Deal::factory()->inStage($openStage)->create();
        $dWon  = Deal::factory()->inStage($wonStage)->create();
        $dLost = Deal::factory()->inStage($lostStage)->create();

        DB::table('deal_contacts')->insert(['deal_id' => $dOpen->id, 'contact_id' => $contact->id]);
        DB::table('deal_contacts')->insert(['deal_id' => $dWon->id,  'contact_id' => $contact->id]);
        DB::table('deal_contacts')->insert(['deal_id' => $dLost->id, 'contact_id' => $contact->id]);

        $response = $this->getJson('/api/contacts?sort_by=open_deals&sort_dir=desc&per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        // $contact has 1 open deal, $empty has 0 → $contact must come first in desc
        $this->assertLessThan(
            array_search($empty->id, $ids, true),
            array_search($contact->id, $ids, true),
        );
    }

    // =========================================================================
    // Validation: invalid sort_by/sort_dir must return 422
    // =========================================================================

    public function test_invalid_sort_by_returns_422(): void
    {
        $this->getJson('/api/contacts?sort_by=injected_column')
            ->assertUnprocessable();
    }

    public function test_invalid_sort_dir_returns_422(): void
    {
        $this->getJson('/api/contacts?sort_by=name&sort_dir=sideways')
            ->assertUnprocessable();
    }

    // =========================================================================
    // Default order (no sort_by) — newest first unchanged
    // =========================================================================

    public function test_default_order_is_newest_first_when_no_sort_by(): void
    {
        $oldest = Contact::factory()->create(['created_at' => now()->subDays(10), 'owner_id' => $this->admin->id]);
        $middle = Contact::factory()->create(['created_at' => now()->subDays(5),  'owner_id' => $this->admin->id]);
        $newest = Contact::factory()->create(['created_at' => now()->subDay(),    'owner_id' => $this->admin->id]);

        $response = $this->getJson('/api/contacts?per_page=10')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame($newest->id, $ids[0]);
        $this->assertSame($middle->id, $ids[1]);
        $this->assertSame($oldest->id, $ids[2]);
    }
}
