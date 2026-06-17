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
        $this->service = new DedupService;
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
