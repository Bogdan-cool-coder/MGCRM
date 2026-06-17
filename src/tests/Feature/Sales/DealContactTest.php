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
