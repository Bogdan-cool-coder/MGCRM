<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Models\DismissedDuplicate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DedupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->user, ['*']);
    }

    // ---- Scan ----

    public function test_scan_finds_contact_with_same_email(): void
    {
        $c1 = Contact::factory()->create(['email' => 'dup@example.com', 'owner_id' => $this->user->id]);
        $c2 = Contact::factory()->create(['email' => 'dup@example.com', 'owner_id' => $this->user->id]);

        $this->getJson("/api/crm/dedup/scan?scope=contact&entity_id={$c1->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $c2->id]);
    }

    public function test_scan_finds_company_with_same_tax_id(): void
    {
        $co1 = Company::factory()->create(['tax_id' => '12345678901', 'owner_user_id' => $this->user->id]);
        $co2 = Company::factory()->create(['tax_id' => '12345678901', 'owner_user_id' => $this->user->id]);

        $this->getJson("/api/crm/dedup/scan?scope=company&entity_id={$co1->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $co2->id]);
    }

    public function test_scan_excludes_dismissed_pairs(): void
    {
        $c1 = Contact::factory()->create(['email' => 'dup2@example.com', 'owner_id' => $this->user->id]);
        $c2 = Contact::factory()->create(['email' => 'dup2@example.com', 'owner_id' => $this->user->id]);

        DismissedDuplicate::create([
            'entity_type' => 'contact',
            'entity_a_id' => min($c1->id, $c2->id),
            'entity_b_id' => max($c1->id, $c2->id),
            'dismissed_by_user_id' => $this->user->id,
            'dismissed_at' => now(),
        ]);

        $this->getJson("/api/crm/dedup/scan?scope=contact&entity_id={$c1->id}")
            ->assertOk()
            ->assertJsonMissing(['id' => $c2->id]);
    }

    // ---- Merge ----

    public function test_merge_contacts_moves_links_to_master(): void
    {
        $master = Contact::factory()->create(['owner_id' => $this->user->id]);
        $dup = Contact::factory()->create(['owner_id' => $this->user->id]);
        $co = Company::factory()->create(['owner_user_id' => $this->user->id]);

        ContactCompanyLink::create([
            'contact_id' => $dup->id,
            'company_id' => $co->id,
            'employment_status' => 'works',
            'is_primary' => false,
        ]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $master->id,
            'duplicate_ids' => [$dup->id],
        ])
            ->assertOk();

        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $master->id,
            'company_id' => $co->id,
        ]);

        $this->assertSoftDeleted('crm_contacts', ['id' => $dup->id]);
    }

    public function test_merge_fails_when_master_in_duplicate_ids(): void
    {
        $c = Contact::factory()->create(['owner_id' => $this->user->id]);

        $this->postJson('/api/crm/dedup/merge', [
            'scope' => 'contact',
            'master_id' => $c->id,
            'duplicate_ids' => [$c->id],
        ])
            ->assertStatus(500);
    }

    // ---- Dismiss ----

    public function test_dismiss_creates_dismissed_record(): void
    {
        $c1 = Contact::factory()->create(['owner_id' => $this->user->id]);
        $c2 = Contact::factory()->create(['owner_id' => $this->user->id]);

        $this->postJson('/api/crm/dedup/dismiss', [
            'scope' => 'contact',
            'entity_a_id' => $c1->id,
            'entity_b_id' => $c2->id,
        ])
            ->assertOk();

        $this->assertDatabaseHas('dismissed_duplicates', [
            'entity_type' => 'contact',
            'entity_a_id' => min($c1->id, $c2->id),
            'entity_b_id' => max($c1->id, $c2->id),
        ]);
    }

    public function test_dismiss_is_idempotent(): void
    {
        $c1 = Contact::factory()->create(['owner_id' => $this->user->id]);
        $c2 = Contact::factory()->create(['owner_id' => $this->user->id]);

        $payload = [
            'scope' => 'contact',
            'entity_a_id' => $c1->id,
            'entity_b_id' => $c2->id,
        ];

        $this->postJson('/api/crm/dedup/dismiss', $payload)->assertOk();
        $this->postJson('/api/crm/dedup/dismiss', $payload)->assertOk();

        $this->assertSame(1, DismissedDuplicate::where('entity_type', 'contact')->count());
    }
}
