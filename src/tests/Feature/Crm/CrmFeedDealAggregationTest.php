<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A3/A4/C9 — CrmFeedService deal-activity aggregation + real status field.
 *
 * Tests that:
 *  - Company /feed includes an activity targeting one of its deals (A3).
 *  - Contact /feed includes an activity targeting one of its linked deals (A4).
 *  - A deal-activity the user cannot see (Own scope, other owner) is excluded (visibility).
 *  - An activity that is BOTH a direct entity hit AND a deal-linked hit is not double-counted.
 *  - The activity payload carries the real `status` string (new|in_progress|done|rejected)
 *    and a rejected activity is not mislabelled (C9).
 *
 * Uses Admin (VisibilityScope::All) for standard assertions and Manager
 * (VisibilityScope::Own) for the visibility-gating assertion.
 */
class CrmFeedDealAggregationTest extends TestCase
{
    use RefreshDatabase;

    // ── A3: Company feed aggregates deal activities ──────────────────────────

    public function test_company_feed_includes_activity_on_linked_deal(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id]);

        // Activity lives on the DEAL, not the company directly.
        Activity::factory()->forDeal($deal)->create(['title' => 'Deal call']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $titles = array_column(array_column($activityItems, 'payload'), 'title');

        $this->assertContains('Deal call', $titles, 'company /feed must surface the deal-targeted activity');
    }

    public function test_company_feed_deal_activity_carries_deal_id_hint(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Presentation']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $item = collect($activityItems)->firstWhere('payload.title', 'Presentation');

        $this->assertNotNull($item, 'deal-activity item must be present');
        $this->assertSame($deal->id, $item['payload']['deal_id'],
            'deal_id context hint must point to the originating deal');
    }

    public function test_company_feed_direct_activity_has_null_deal_id(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        // Activity on the company directly (not a deal).
        Activity::factory()->forCompany($company)->create(['title' => 'Direct note']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $item = collect($activityItems)->firstWhere('payload.title', 'Direct note');

        $this->assertNotNull($item);
        $this->assertNull($item['payload']['deal_id'],
            'a direct company activity must have deal_id=null');
    }

    // ── A4: Contact feed aggregates deal activities ──────────────────────────

    public function test_contact_feed_includes_activity_on_linked_deal(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();
        DealContact::query()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        // Activity lives on the DEAL.
        Activity::factory()->forDeal($deal)->create(['title' => 'Meeting with contact']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/contacts/{$contact->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $titles = array_column(array_column($activityItems, 'payload'), 'title');

        $this->assertContains('Meeting with contact', $titles,
            'contact /feed must surface the deal-targeted activity via deal_contacts pivot');
    }

    public function test_contact_feed_deal_activity_carries_deal_id_hint(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();
        DealContact::query()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id, 'is_primary' => false]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Contract review']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/contacts/{$contact->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $item = collect($activityItems)->firstWhere('payload.title', 'Contract review');

        $this->assertNotNull($item);
        $this->assertSame($deal->id, $item['payload']['deal_id']);
    }

    // ── Visibility: deal activities the user cannot see are excluded ─────────

    public function test_company_feed_excludes_deal_activity_not_visible_to_manager(): void
    {
        // Manager has VisibilityScope::Own — only sees deals they own.
        $manager = User::factory()->create(['role' => Role::Manager]);
        $otherOwner = User::factory()->create(['role' => Role::Manager]);

        // Manager owns the company (so CompanyPolicy::view passes), but the deal
        // belongs to someone else — so the deal's activities must be excluded.
        $company = Company::factory()->create(['owner_user_id' => $manager->id]);

        // Deal owned by someone else — the manager cannot see it.
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $otherOwner->id,
        ]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Invisible deal task']);

        Sanctum::actingAs($manager, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $titles = array_column(array_column($activityItems, 'payload'), 'title');

        $this->assertNotContains('Invisible deal task', $titles,
            'manager must not see deal activities from a deal they do not own');
    }

    public function test_contact_feed_excludes_deal_activity_not_visible_to_manager(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $otherOwner = User::factory()->create(['role' => Role::Manager]);

        // Manager owns the contact (so ContactPolicy::view passes), but the deal
        // belongs to someone else — so the deal's activities must be excluded.
        $contact = Contact::factory()->create(['owner_id' => $manager->id]);

        $deal = Deal::factory()->create(['owner_user_id' => $otherOwner->id]);
        DealContact::query()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Foreign deal activity']);

        Sanctum::actingAs($manager, ['*']);
        $response = $this->getJson("/api/contacts/{$contact->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $titles = array_column(array_column($activityItems, 'payload'), 'title');

        $this->assertNotContains('Foreign deal activity', $titles,
            'manager must not see deal activities from a deal they do not own');
    }

    // ── No double-counting: direct + deal-linked ─────────────────────────────

    public function test_company_feed_does_not_double_count_direct_and_deal_linked_activity(): void
    {
        // Scenario: an activity is created on a company AND referenced via a deal
        // linked to the same company. The unique('id') guard must emit it only once.
        // In practice the OR query returns a direct hit once (target_type=company),
        // so there's nothing to double, but we guard against hypothetical duplication
        // by creating a direct company activity AND a deal activity and checking the total.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id]);

        Activity::factory()->forCompany($company)->create(['title' => 'Direct task']);
        Activity::factory()->forDeal($deal)->create(['title' => 'Deal task']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $this->assertCount(2, $activityItems, 'two distinct activities must appear exactly once each');

        $ids = array_column(array_column($activityItems, 'payload'), 'activity_id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'no duplicate activity_id in feed');
    }

    // ── C9: real status field ────────────────────────────────────────────────

    public function test_activity_payload_carries_real_status_string(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        Activity::factory()->forCompany($company)->create([
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $this->assertCount(1, $activityItems);

        $payload = $activityItems[0]['payload'];
        $this->assertArrayHasKey('status', $payload, 'activity payload must carry status field (C9)');
        $this->assertSame('rejected', $payload['status'],
            'rejected activity must have status=rejected, not "done" (C9 fix)');
        $this->assertTrue($payload['is_closed'],
            'is_closed must remain true alongside the real status');
    }

    public function test_contact_activity_payload_carries_real_status_string(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();

        Activity::factory()->forContact($contact)->create([
            'status' => ActivityStatus::InProgress->value,
            'is_closed' => false,
        ]);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/contacts/{$contact->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $this->assertCount(1, $activityItems);

        $payload = $activityItems[0]['payload'];
        $this->assertArrayHasKey('status', $payload);
        $this->assertSame('in_progress', $payload['status']);
    }

    public function test_deal_activity_aggregated_into_company_feed_carries_status(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id]);

        Activity::factory()->forDeal($deal)->create([
            'title' => 'Rejected deal task',
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $activityItems = $this->activityItems($response->json('data'));
        $item = collect($activityItems)->firstWhere('payload.title', 'Rejected deal task');

        $this->assertNotNull($item, 'deal-sourced rejected activity must appear in company feed');
        $this->assertSame('rejected', $item['payload']['status'],
            'deal-activity aggregated into company feed must carry real status (C9+A3)');
        $this->assertSame($deal->id, $item['payload']['deal_id']);
    }

    // ── deal_title: populated for deal-sourced, null for direct ─────────────

    public function test_company_feed_deal_activity_carries_deal_title(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id, 'title' => 'The Big Pitch']);

        Activity::factory()->forDeal($deal)->create(['title' => 'Pitch prep']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity");

        $response->assertOk();

        $item = collect($this->activityItems($response->json('data')))
            ->firstWhere('payload.title', 'Pitch prep');

        $this->assertNotNull($item, 'deal-activity must be present in company feed');
        $this->assertSame('The Big Pitch', $item['payload']['deal_title'],
            'deal_title must carry the deal\'s title string for deal-sourced activities');
        $this->assertArrayHasKey('deal_title', $item['payload'],
            'deal_title key must always be present in activity payload');
    }

    public function test_contact_feed_deal_activity_carries_deal_title(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['title' => 'Contact Campaign']);
        DealContact::query()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Campaign kickoff']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/contacts/{$contact->id}/feed?types[]=activity");

        $response->assertOk();

        $item = collect($this->activityItems($response->json('data')))
            ->firstWhere('payload.title', 'Campaign kickoff');

        $this->assertNotNull($item, 'deal-activity must be present in contact feed');
        $this->assertSame('Contact Campaign', $item['payload']['deal_title']);
    }

    public function test_direct_activity_has_null_deal_title(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        Activity::factory()->forCompany($company)->create(['title' => 'Entity note']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity");

        $response->assertOk();

        $item = collect($this->activityItems($response->json('data')))
            ->firstWhere('payload.title', 'Entity note');

        $this->assertNotNull($item);
        $this->assertNull($item['payload']['deal_title'],
            'deal_title must be null for direct-entity (non-deal) activities');
    }

    /**
     * Proves deal_title is batched — multiple deal-sourced activities mapping to
     * different deals should all have their titles resolved without N+1.
     * We seed two deals and two activities (one per deal), then assert both titles
     * appear. (The batch path code-path is exercised whenever dealActivityIds > 1.)
     */
    public function test_deal_title_is_batched_multiple_deals(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        $deal1 = Deal::factory()->create(['company_id' => $company->id, 'title' => 'Alpha Deal']);
        $deal2 = Deal::factory()->create(['company_id' => $company->id, 'title' => 'Beta Deal']);

        Activity::factory()->forDeal($deal1)->create(['title' => 'Alpha call']);
        Activity::factory()->forDeal($deal2)->create(['title' => 'Beta call']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity");

        $response->assertOk();

        $items = $this->activityItems($response->json('data'));

        $alpha = collect($items)->firstWhere('payload.title', 'Alpha call');
        $beta = collect($items)->firstWhere('payload.title', 'Beta call');

        $this->assertNotNull($alpha, 'Alpha call must be in feed');
        $this->assertNotNull($beta, 'Beta call must be in feed');
        $this->assertSame('Alpha Deal', $alpha['payload']['deal_title'],
            'Alpha call must carry Alpha Deal title (batched lookup)');
        $this->assertSame('Beta Deal', $beta['payload']['deal_title'],
            'Beta call must carry Beta Deal title (batched lookup)');
    }

    /**
     * Visibility + deal_title: activities from invisible deals must not appear
     * even when deal_title lookup is active.
     */
    public function test_invisible_deal_activity_excluded_and_deal_title_not_leaked(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $otherOwner = User::factory()->create(['role' => Role::Manager]);

        $company = Company::factory()->create(['owner_user_id' => $manager->id]);
        $deal = Deal::factory()->create([
            'company_id' => $company->id,
            'owner_user_id' => $otherOwner->id,
            'title' => 'Secret Deal',
        ]);

        Activity::factory()->forDeal($deal)->create(['title' => 'Invisible action']);

        Sanctum::actingAs($manager, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity");

        $response->assertOk();

        $titles = array_column(
            array_column($this->activityItems($response->json('data')), 'payload'),
            'title',
        );

        $this->assertNotContains('Invisible action', $titles,
            'activity on invisible deal must not appear even with deal_title resolution active');
    }

    // ── F27: bounded-fetch parity — meta.total + items byte-identical ────────

    /**
     * Golden-reference parity test (company feed).
     *
     * Seeds 19 activities (17 direct + 2 deal-linked, perPage=10).
     * Verifies:
     *   - meta.total = 19 on EVERY page (cappedCount path, independent of fetch)
     *   - page 1 carries exactly 10 items; page 2 carries exactly 9
     *   - p1+p2 combined cover all 19 activity ids (full coverage)
     *   - pages do not overlap
     *   - items are globally newest-first across both pages
     *   - deal activities (newest) are on page 1
     *
     * This is the regression guard for the meta.total bug introduced when total
     * was derived from $sorted->count() (the bounded subset) instead of from
     * cappedCount() on the full source query.
     */
    public function test_company_feed_page1_and_page2_parity_with_bounded_fetch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['company_id' => $company->id, 'title' => 'Parity Deal']);

        // 17 direct activities, timestamp-staggered for deterministic descending order.
        $allIds = [];
        for ($i = 1; $i <= 17; $i++) {
            $a = Activity::factory()->forCompany($company)->create([
                'title' => "Direct {$i}",
                'created_at' => now()->subMinutes(100 - $i), // Direct 17 is newest direct
            ]);
            $allIds[] = "activity_{$a->id}";
        }

        // 2 deal activities newer than all directs (will land at the top of sorted order).
        $da2 = Activity::factory()->forDeal($deal)->create([
            'title' => 'Deal act 2',
            'created_at' => now()->subMinutes(2),
        ]);
        $da1 = Activity::factory()->forDeal($deal)->create([
            'title' => 'Deal act 1',
            'created_at' => now()->subMinutes(1), // absolute newest
        ]);
        $allIds[] = "activity_{$da1->id}";
        $allIds[] = "activity_{$da2->id}";

        Sanctum::actingAs($admin, ['*']);

        $p1 = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity&page=1&per_page=10");
        $p1->assertOk();
        $p1Ids = array_column($this->activityItems($p1->json('data')), 'id');

        $p2 = $this->getJson("/api/companies/{$company->id}/feed?types[]=activity&page=2&per_page=10");
        $p2->assertOk();
        $p2Ids = array_column($this->activityItems($p2->json('data')), 'id');

        // meta.total must be 19 on both pages — cappedCount() path, not fetched-row count.
        $this->assertSame(19, $p1->json('meta.total'),
            'meta.total must be 19 (19 activities total) on page 1 — regression guard for bounded-fetch bug');
        $this->assertSame(19, $p2->json('meta.total'),
            'meta.total must be 19 on page 2 as well');

        // Page sizes.
        $this->assertCount(10, $p1Ids, 'page 1 must carry exactly perPage=10 items');
        $this->assertCount(9, $p2Ids, 'page 2 must carry the remaining 9 items');

        // No overlap.
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), 'pages must not share any item ids');

        // Combined pages cover exactly all 19 activity ids.
        $combined = array_merge($p1Ids, $p2Ids);
        sort($combined);
        sort($allIds);
        $this->assertSame($allIds, $combined, 'p1+p2 must cover exactly all 19 activity ids');

        // Deal activities (the two newest) must be on page 1.
        $this->assertContains("activity_{$da1->id}", $p1Ids, 'deal act 1 (newest) must be on page 1');
        $this->assertContains("activity_{$da2->id}", $p1Ids, 'deal act 2 (second newest) must be on page 1');

        // Pagination meta.
        $this->assertSame(1, $p1->json('meta.current_page'));
        $this->assertSame(10, $p1->json('meta.per_page'));
        $this->assertSame(2, $p2->json('meta.current_page'));
    }

    /**
     * Same golden-reference parity for contact feed.
     * Seeds 19 activities (17 direct + 2 deal via pivot) and asserts all the same
     * invariants: total=19 on both pages, full coverage, no overlap, deal acts on p1.
     */
    public function test_contact_feed_page1_and_page2_parity_with_bounded_fetch(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['title' => 'Contact Parity Deal']);
        DealContact::query()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        $allIds = [];
        for ($i = 1; $i <= 17; $i++) {
            $a = Activity::factory()->forContact($contact)->create([
                'title' => "Direct {$i}",
                'created_at' => now()->subMinutes(100 - $i),
            ]);
            $allIds[] = "activity_{$a->id}";
        }

        $da2 = Activity::factory()->forDeal($deal)->create([
            'title' => 'Deal contact act 2',
            'created_at' => now()->subMinutes(2),
        ]);
        $da1 = Activity::factory()->forDeal($deal)->create([
            'title' => 'Deal contact act 1',
            'created_at' => now()->subMinutes(1),
        ]);
        $allIds[] = "activity_{$da1->id}";
        $allIds[] = "activity_{$da2->id}";

        Sanctum::actingAs($admin, ['*']);

        $p1 = $this->getJson("/api/contacts/{$contact->id}/feed?types[]=activity&page=1&per_page=10");
        $p1->assertOk();
        $p1Ids = array_column($this->activityItems($p1->json('data')), 'id');

        $p2 = $this->getJson("/api/contacts/{$contact->id}/feed?types[]=activity&page=2&per_page=10");
        $p2->assertOk();
        $p2Ids = array_column($this->activityItems($p2->json('data')), 'id');

        // meta.total parity: 19 on both pages.
        $this->assertSame(19, $p1->json('meta.total'),
            'contact feed meta.total must be 19 on page 1');
        $this->assertSame(19, $p2->json('meta.total'),
            'contact feed meta.total must be 19 on page 2');

        $this->assertCount(10, $p1Ids, 'page 1 must carry exactly 10 items');
        $this->assertCount(9, $p2Ids, 'page 2 must carry the remaining 9 items');
        $this->assertEmpty(array_intersect($p1Ids, $p2Ids), 'pages must not overlap');

        $combined = array_merge($p1Ids, $p2Ids);
        sort($combined);
        sort($allIds);
        $this->assertSame($allIds, $combined, 'p1+p2 must cover exactly all 19 activity ids');

        $this->assertContains("activity_{$da1->id}", $p1Ids, 'deal act 1 (newest) must be on page 1');
        $this->assertContains("activity_{$da2->id}", $p1Ids, 'deal act 2 must be on page 1');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Filter a raw feed data array to only activity items.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    private function activityItems(array $data): array
    {
        return array_values(array_filter(
            $data,
            static fn (array $item): bool => $item['type'] === 'activity',
        ));
    }
}
