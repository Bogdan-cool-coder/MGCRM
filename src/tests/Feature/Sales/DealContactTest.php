<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealContactTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function setupDeal(): array
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $company->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        return [$deal, $company];
    }

    public function test_add_contact_creates_company_link(): void
    {
        [$deal, $company] = $this->setupDeal();
        $contact = Contact::factory()->create();

        $this->postJson("/api/deals/{$deal->id}/contacts", ['contact_id' => $contact->id])
            ->assertCreated()
            ->assertJsonPath('data.contact.id', $contact->id);

        $this->assertDatabaseHas('deal_contacts', [
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);
        $this->assertDatabaseHas('crm_contact_company_links', [
            'contact_id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_add_contact_unique_per_deal(): void
    {
        [$deal] = $this->setupDeal();
        $contact = Contact::factory()->create();

        $this->postJson("/api/deals/{$deal->id}/contacts", ['contact_id' => $contact->id])
            ->assertCreated();

        $this->postJson("/api/deals/{$deal->id}/contacts", ['contact_id' => $contact->id])
            ->assertStatus(409);
    }

    public function test_only_one_primary_contact_per_deal(): void
    {
        [$deal] = $this->setupDeal();
        $first = Contact::factory()->create();
        $second = Contact::factory()->create();

        $this->postJson("/api/deals/{$deal->id}/contacts", [
            'contact_id' => $first->id,
            'is_primary' => true,
        ])->assertCreated();

        $this->postJson("/api/deals/{$deal->id}/contacts", [
            'contact_id' => $second->id,
            'is_primary' => true,
        ])->assertCreated();

        // The earlier primary must have been demoted (partial unique honoured).
        $primaryCount = DealContact::where('deal_id', $deal->id)
            ->where('is_primary', true)
            ->count();
        $this->assertSame(1, $primaryCount);
        $this->assertDatabaseHas('deal_contacts', [
            'deal_id' => $deal->id,
            'contact_id' => $second->id,
            'is_primary' => true,
        ]);
    }

    public function test_set_primary_promotes_and_demotes_previous(): void
    {
        [$deal] = $this->setupDeal();
        $first = Contact::factory()->create();
        $second = Contact::factory()->create();

        $firstLink = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $first->id,
            'is_primary' => true,
        ]);
        $secondLink = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $second->id,
            'is_primary' => false,
        ]);

        $this->patchJson("/api/deals/{$deal->id}/contacts/{$secondLink->id}", [
            'is_primary' => true,
        ])->assertOk();

        // Previous primary demoted, new one promoted — partial unique honoured.
        $this->assertDatabaseHas('deal_contacts', ['id' => $secondLink->id, 'is_primary' => true]);
        $this->assertDatabaseHas('deal_contacts', ['id' => $firstLink->id, 'is_primary' => false]);
        $this->assertSame(
            1,
            DealContact::where('deal_id', $deal->id)->where('is_primary', true)->count(),
        );
    }

    public function test_set_primary_unset_clears_flag(): void
    {
        [$deal] = $this->setupDeal();
        $contact = Contact::factory()->create();
        $link = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        $this->patchJson("/api/deals/{$deal->id}/contacts/{$link->id}", [
            'is_primary' => false,
        ])->assertOk();

        $this->assertDatabaseHas('deal_contacts', ['id' => $link->id, 'is_primary' => false]);
    }

    public function test_set_primary_requires_is_primary(): void
    {
        [$deal] = $this->setupDeal();
        $contact = Contact::factory()->create();
        $link = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);

        $this->patchJson("/api/deals/{$deal->id}/contacts/{$link->id}", [])
            ->assertStatus(422);
    }

    public function test_set_primary_link_of_other_deal_is_404(): void
    {
        [$deal] = $this->setupDeal();
        $otherDeal = Deal::factory()->forOwner(User::factory()->create(['role' => Role::Manager]))->create([
            'pipeline_id' => $deal->pipeline_id,
            'stage_id' => $deal->stage_id,
            'company_id' => $deal->company_id,
        ]);
        $contact = Contact::factory()->create();
        $foreignLink = DealContact::factory()->create([
            'deal_id' => $otherDeal->id,
            'contact_id' => $contact->id,
        ]);

        $this->patchJson("/api/deals/{$deal->id}/contacts/{$foreignLink->id}", [
            'is_primary' => true,
        ])->assertStatus(404);
    }

    public function test_remove_contact(): void
    {
        [$deal] = $this->setupDeal();
        $contact = Contact::factory()->create();
        $link = DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);

        $this->deleteJson("/api/deals/{$deal->id}/contacts/{$link->id}")->assertNoContent();

        $this->assertDatabaseMissing('deal_contacts', ['id' => $link->id]);
    }
}
