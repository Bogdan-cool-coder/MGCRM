<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Models\ContactPosition;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Crm\Services\ContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for ContactService::purgeAll() — system-reset `contacts` category
 * (docs/contracts/system-reset-api-contract.md §1 row 2).
 *
 * Coverage:
 *   - deletes crm_contact_relations, crm_contact_company_links, contact_channels, crm_contacts
 *   - returns accurate per-table counts
 *   - hard-deletes (forceDelete) — including already soft-deleted contacts
 *   - never-delete dictionary crm_contact_positions is left untouched (allow-list invariant)
 *   - safe to call on an already-empty table (returns all zeros)
 */
class ContactServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactService::class);
    }

    public function test_purges_contacts_and_all_child_tables(): void
    {
        $contactA = Contact::factory()->create();
        $contactB = Contact::factory()->create();

        ContactChannel::create([
            'contact_id' => $contactA->id,
            'channel_type' => 'phone',
            'value' => '+77001234567',
        ]);

        ContactRelation::create([
            'contact_id' => $contactA->id,
            'related_contact_id' => $contactB->id,
            'relation_type' => 'colleague',
        ]);

        $company = Company::factory()->create();
        ContactCompanyLink::create([
            'contact_id' => $contactA->id,
            'company_id' => $company->id,
            'employment_status' => 'works',
            'is_primary' => true,
        ]);

        $counts = $this->service->purgeAll();

        $this->assertSame([
            'crm_contact_relations' => 1,
            'crm_contact_company_links' => 1,
            'contact_channels' => 1,
            'crm_contacts' => 2,
        ], $counts);

        $this->assertSame(0, DB::table('crm_contact_relations')->count());
        $this->assertSame(0, DB::table('crm_contact_company_links')->count());
        $this->assertSame(0, DB::table('contact_channels')->count());
        $this->assertSame(0, Contact::withTrashed()->count());

        // Company itself is untouched — purging contacts must not cascade into companies.
        $this->assertSame(1, DB::table('crm_companies')->count());
    }

    public function test_hard_deletes_already_soft_deleted_contacts(): void
    {
        $contact = Contact::factory()->create();
        $contact->delete(); // soft delete

        $this->assertSame(1, Contact::withTrashed()->count());
        $this->assertSame(0, Contact::query()->count());

        $counts = $this->service->purgeAll();

        $this->assertSame(1, $counts['crm_contacts']);
        $this->assertSame(0, Contact::withTrashed()->count());
    }

    public function test_never_touches_crm_contact_positions_dictionary(): void
    {
        ContactPosition::create(['name' => 'CEO', 'sort_order' => 1, 'is_active' => true]);
        Contact::factory()->create();

        $this->service->purgeAll();

        $this->assertSame(1, DB::table('crm_contact_positions')->count());
    }

    public function test_is_safe_to_call_when_no_contacts_exist(): void
    {
        $counts = $this->service->purgeAll();

        $this->assertSame([
            'crm_contact_relations' => 0,
            'crm_contact_company_links' => 0,
            'contact_channels' => 0,
            'crm_contacts' => 0,
        ], $counts);
    }
}
