<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Services\DedupService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DedupServiceTest extends TestCase
{
    use RefreshDatabase;

    private DedupService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(DedupService::class);
        $this->actor = User::factory()->create();
    }

    public function test_scan_contact_by_email(): void
    {
        $c1 = Contact::factory()->create(['email' => 'same@example.com']);
        $c2 = Contact::factory()->create(['email' => 'same@example.com']);
        Contact::factory()->create(['email' => 'other@example.com']);

        $results = $this->service->scan('contact', $c1->id);

        $this->assertCount(1, $results);
        $this->assertSame($c2->id, $results->first()->id);
    }

    public function test_scan_company_by_tax_id(): void
    {
        $co1 = Company::factory()->create(['tax_id' => '999888777']);
        $co2 = Company::factory()->create(['tax_id' => '999888777']);
        Company::factory()->create(['tax_id' => '111222333']);

        $results = $this->service->scan('company', $co1->id);

        $this->assertCount(1, $results);
        $this->assertSame($co2->id, $results->first()->id);
    }

    public function test_dismiss_normalizes_id_order(): void
    {
        $c1 = Contact::factory()->create();
        $c2 = Contact::factory()->create();

        // Pass in reverse order
        $this->service->dismiss('contact', max($c1->id, $c2->id), min($c1->id, $c2->id), $this->actor);

        $this->assertDatabaseHas('dismissed_duplicates', [
            'entity_type' => 'contact',
            'entity_a_id' => min($c1->id, $c2->id),
            'entity_b_id' => max($c1->id, $c2->id),
        ]);
    }

    public function test_merge_contact_transfers_links(): void
    {
        $master = Contact::factory()->create();
        $dup = Contact::factory()->create();
        $company = Company::factory()->create();

        ContactCompanyLink::create([
            'contact_id' => $dup->id,
            'company_id' => $company->id,
            'employment_status' => 'works',
            'is_primary' => false,
        ]);

        $this->service->merge('contact', $master->id, [$dup->id], $this->actor);

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $master->id,
            'company_id' => $company->id,
        ]);

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
    }

    public function test_merge_throws_when_master_in_duplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $c = Contact::factory()->create();
        $this->service->merge('contact', $c->id, [$c->id], $this->actor);
    }

    public function test_scan_excludes_soft_deleted(): void
    {
        $c1 = Contact::factory()->create(['email' => 'alive@example.com']);
        $c2 = Contact::factory()->create(['email' => 'alive@example.com']);
        $c2->delete(); // soft-delete

        $results = $this->service->scan('contact', $c1->id);

        $this->assertCount(0, $results);
    }

    public function test_invalid_scope_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->scan('deal', 1);
    }

    // =========================================================================
    // BUG-4: scanAll must not return the same entity in two groups
    // =========================================================================

    /**
     * 3 contacts share BOTH email and phone.
     * scanAll must return exactly 1 group containing all three, not two separate
     * groups (one per criterion) that both list the same contacts.
     */
    public function test_scan_all_contacts_three_share_email_and_phone_yields_single_group(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $c1 = Contact::factory()->create([
            'email' => 'ivan@example.com',
            'phone' => '+77001234567',
            'full_name' => 'Иван Петров',
            'owner_id' => $admin->id,
        ]);
        $c2 = Contact::factory()->create([
            'email' => 'ivan@example.com',
            'phone' => '+77001234567',
            'full_name' => 'Иван Петров',
            'owner_id' => $admin->id,
        ]);
        $c3 = Contact::factory()->create([
            'email' => 'ivan@example.com',
            'phone' => '+77001234567',
            'full_name' => 'Иван Петров',
            'owner_id' => $admin->id,
        ]);

        $groups = $this->service->scanAll('contact', $admin);

        $this->assertCount(1, $groups, 'Expected exactly 1 merged group, not multiple per-criterion groups');

        $groupIds = collect($groups->first()['entities'])->pluck('id')->sort()->values()->all();
        $expectedIds = collect([$c1->id, $c2->id, $c3->id])->sort()->values()->all();

        $this->assertSame($expectedIds, $groupIds, 'The single group must contain all three contacts');
    }

    /**
     * 3 contacts share same name AND phone but different emails — still 1 group.
     * Contact A and B share phone. Contact B and C share name.
     * A↔B (phone) and B↔C (name) → connected component {A, B, C} = 1 group.
     */
    public function test_scan_all_contacts_chain_overlap_yields_single_group(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        // A and B share phone
        $cA = Contact::factory()->create([
            'email' => 'contactA@example.com',
            'phone' => '+77009999999',
            'full_name' => 'Shared Name',
            'owner_id' => $admin->id,
        ]);
        $cB = Contact::factory()->create([
            'email' => 'contactB@example.com',
            'phone' => '+77009999999', // same phone as A
            'full_name' => 'Shared Name', // same name as C
            'owner_id' => $admin->id,
        ]);
        $cC = Contact::factory()->create([
            'email' => 'contactC@example.com',
            'phone' => '+77008888888', // different phone
            'full_name' => 'Shared Name', // same name as B
            'owner_id' => $admin->id,
        ]);

        $groups = $this->service->scanAll('contact', $admin);

        // All three are reachable from each other: A↔B via phone, B↔C via name
        // so they must be in one connected component.
        $this->assertCount(1, $groups, 'Chain-overlap contacts must collapse into a single group');

        $groupIds = collect($groups->first()['entities'])->pluck('id')->sort()->values()->all();
        $expectedIds = collect([$cA->id, $cB->id, $cC->id])->sort()->values()->all();

        $this->assertSame($expectedIds, $groupIds);
    }

    /**
     * Two independent duplicate pairs must remain as two separate groups.
     */
    public function test_scan_all_contacts_disjoint_pairs_remain_separate_groups(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        // Pair 1: share email
        $p1a = Contact::factory()->create(['email' => 'pair1@example.com', 'full_name' => 'Alpha One', 'owner_id' => $admin->id]);
        $p1b = Contact::factory()->create(['email' => 'pair1@example.com', 'full_name' => 'Alpha Two', 'owner_id' => $admin->id]);

        // Pair 2: share phone (different email, different name)
        $p2a = Contact::factory()->create(['email' => 'beta1@example.com', 'phone' => '+77005556677', 'full_name' => 'Beta One', 'owner_id' => $admin->id]);
        $p2b = Contact::factory()->create(['email' => 'beta2@example.com', 'phone' => '+77005556677', 'full_name' => 'Beta Two', 'owner_id' => $admin->id]);

        $groups = $this->service->scanAll('contact', $admin);

        $this->assertCount(2, $groups, 'Two disjoint duplicate pairs must produce exactly 2 groups');

        $allGroupIds = $groups->map(fn ($g) => collect($g['entities'])->pluck('id')->sort()->values()->all())->all();

        $pair1Ids = collect([$p1a->id, $p1b->id])->sort()->values()->all();
        $pair2Ids = collect([$p2a->id, $p2b->id])->sort()->values()->all();

        $this->assertTrue(
            in_array($pair1Ids, $allGroupIds, false) || $this->groupContainsIds($allGroupIds, $pair1Ids),
            'Pair 1 must be in one group'
        );
        $this->assertTrue(
            $this->groupContainsIds($allGroupIds, $pair2Ids),
            'Pair 2 must be in one group'
        );
    }

    // =========================================================================
    // BUG-2: scanAll must filter dismissed pairs from union-find edges
    // =========================================================================

    /**
     * Dismiss pair A-B; global scan must NOT place A and B in the same group
     * when there is no third record connecting them.
     */
    public function test_scan_all_contacts_dismissed_pair_not_grouped(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $a = Contact::factory()->create([
            'email' => 'shared-bug2@example.com',
            'owner_id' => $admin->id,
        ]);
        $b = Contact::factory()->create([
            'email' => 'shared-bug2@example.com',
            'owner_id' => $admin->id,
        ]);

        // Dismiss A-B.
        $this->service->dismiss('contact', $a->id, $b->id, $admin);

        $groups = $this->service->scanAll('contact', $admin);

        // The dismissed pair is the only potential group — the scan must return nothing.
        // Alternatively, if somehow groups are returned, A and B must not be together.
        $aAndBInSameGroup = false;
        foreach ($groups as $group) {
            $ids = collect($group['entities'])->pluck('id')->all();
            if (in_array($a->id, $ids, true) && in_array($b->id, $ids, true)) {
                $aAndBInSameGroup = true;
                break;
            }
        }

        $this->assertFalse($aAndBInSameGroup, 'Dismissed pair A-B must not appear in the same group');
    }

    /**
     * Dismiss A-B; if a third contact C connects to both A and B through a
     * SEPARATE non-dismissed criterion, A and C (or B and C) may still form
     * a group — but A and B must NOT be the sole reason they are grouped.
     * Specifically: if C shares email with A but NOT with B, and C shares
     * phone with B but NOT with A, and A-B is dismissed:
     * - A↔C are connected via email (not dismissed).
     * - B↔C are connected via phone (not dismissed).
     * - A↔B is dismissed — this edge is removed.
     * Because A-C and B-C are non-dismissed, A, B, C all end up in one
     * component via C (the transitivity is through C, not through the dismissed edge).
     * This is CORRECT: the dismissed-pair record only blocks the direct A-B edge.
     */
    public function test_scan_all_contacts_dismissed_pair_still_in_group_via_third(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $a = Contact::factory()->create([
            'email' => 'abc-email-A@example.com',
            'phone' => '+77001110001',
            'full_name' => 'Person A Bug2',
            'owner_id' => $admin->id,
        ]);
        $b = Contact::factory()->create([
            'email' => 'abc-email-B@example.com',
            'phone' => '+77001110002',
            'full_name' => 'Person B Bug2',
            'owner_id' => $admin->id,
        ]);
        // C shares email with A AND phone with B.
        $c = Contact::factory()->create([
            'email' => 'abc-email-A@example.com', // same email as A
            'phone' => '+77001110002',             // same phone as B
            'full_name' => 'Person C Bug2',
            'owner_id' => $admin->id,
        ]);

        // Dismiss A-B directly.
        $this->service->dismiss('contact', $a->id, $b->id, $admin);

        $groups = $this->service->scanAll('contact', $admin);

        // All three are still reachable from each other via C (A↔C via email, B↔C via phone).
        // So they must be in one group.
        $this->assertCount(1, $groups, 'A, B, C must still form one component when connected via C');

        $groupIds = collect($groups->first()['entities'])->pluck('id')->sort()->values()->all();
        $expectedIds = collect([$a->id, $b->id, $c->id])->sort()->values()->all();
        $this->assertSame($expectedIds, $groupIds);
    }

    /**
     * Dismiss A-B for companies; global scan must not group them together
     * when no third company connects them.
     */
    public function test_scan_all_companies_dismissed_pair_not_grouped(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $a = Company::factory()->create([
            'tax_id' => '555444333bug2',
            'name' => 'Company Alpha Bug2',
            'owner_user_id' => $admin->id,
        ]);
        $b = Company::factory()->create([
            'tax_id' => '555444333bug2',
            'name' => 'Company Beta Bug2',
            'owner_user_id' => $admin->id,
        ]);

        $this->service->dismiss('company', $a->id, $b->id, $admin);

        $groups = $this->service->scanAll('company', $admin);

        $aAndBInSameGroup = false;
        foreach ($groups as $group) {
            $ids = collect($group['entities'])->pluck('id')->all();
            if (in_array($a->id, $ids, true) && in_array($b->id, $ids, true)) {
                $aAndBInSameGroup = true;
                break;
            }
        }

        $this->assertFalse($aAndBInSameGroup, 'Dismissed company pair must not appear in the same group');
    }

    /** Helper: checks whether any group in $allGroupIds contains exactly the $ids. */
    private function groupContainsIds(array $allGroupIds, array $ids): bool
    {
        foreach ($allGroupIds as $groupIds) {
            if ($groupIds === $ids) {
                return true;
            }
        }

        return false;
    }
}
