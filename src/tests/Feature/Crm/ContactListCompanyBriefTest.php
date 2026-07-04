<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data-Layer-Audit-2026-07 §3.5 — a contact↔company link sideloads only a BRIEF
 * company (id/name/country_code/category_code), not the full ~60-field
 * CompanyResource. The display name — the ONLY thing any consumer reads off a
 * link's company (contacts list column AND contact-card companies panel) — is
 * preserved; the fat legal/bank/requisite fields are gone. This holds on both the
 * list and the card, since neither reads more than the name.
 *
 * The full company is still returned wherever a company is fetched DIRECTLY
 * (GET /api/companies/{id}, company card) — that path is untouched.
 */
class ContactListCompanyBriefTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function contactWithCompany(): Contact
    {
        $contact = Contact::factory()->create();
        $company = Company::factory()->create([
            'name' => 'Acme LLC',
            'legal_name' => 'Acme Limited Liability Company',
            'tax_id' => '7700000000',
            'bank' => 'Big Bank',
            'account' => '40702810000000000000',
            'notes' => 'confidential internal note',
            'country_code' => 'kz',
            'category_code' => CategoryCode::L->value,
        ]);

        app(ContactService::class)->linkCompany($contact, $company->id, ['is_primary' => true]);

        return $contact;
    }

    public function test_list_company_link_carries_only_the_brief_shape(): void
    {
        $this->admin();
        $this->contactWithCompany();

        $company = $this->getJson('/api/contacts')
            ->assertOk()
            ->json('data.0.company_links.0.company');

        // Brief keys present.
        $this->assertSame('Acme LLC', $company['name']);
        $this->assertSame('kz', $company['country_code']);
        $this->assertSame(CategoryCode::L->value, $company['category_code']);
        $this->assertSame(['id', 'name', 'country_code', 'category_code'], array_keys($company));

        // Fat card-only fields must be ABSENT from the list link company.
        foreach (['legal_name', 'tax_id', 'bank', 'account', 'notes', 'extra_fields', 'tags'] as $fatField) {
            $this->assertArrayNotHasKey($fatField, $company, "list link company must not carry {$fatField}");
        }
    }

    public function test_card_link_company_is_also_brief_and_keeps_the_name(): void
    {
        $this->admin();
        $contact = $this->contactWithCompany();

        $company = $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->json('data.company_links.0.company');

        // The card companies panel only reads the name — the brief is enough here
        // too, so the link company is brief on the card as well (no fat fields).
        $this->assertSame('Acme LLC', $company['name']);
        $this->assertSame(['id', 'name', 'country_code', 'category_code'], array_keys($company));
        $this->assertArrayNotHasKey('legal_name', $company);
        $this->assertArrayNotHasKey('tax_id', $company);
    }

    public function test_direct_company_fetch_still_returns_the_full_company(): void
    {
        $this->admin();
        $contact = $this->contactWithCompany();
        $companyId = $contact->companyLinks()->first()->company_id;

        // The company card path (fetched directly) is untouched — full fields.
        $company = $this->getJson("/api/companies/{$companyId}")
            ->assertOk()
            ->json('data');

        $this->assertSame('Acme Limited Liability Company', $company['legal_name']);
        $this->assertSame('7700000000', $company['tax_id']);
        $this->assertArrayHasKey('bank', $company);
        $this->assertArrayHasKey('account', $company);
    }
}
