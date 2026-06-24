<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Crm\Services\DedupService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MAJOR #3 regression tests — phone_normalized indexed column.
 *
 * Ensures that:
 * 1. CompanyService.create sets phone_normalized (digits-only) for new companies.
 * 2. CompanyService.update keeps phone_normalized in sync.
 * 3. ContactService.create / update maintain phone_normalized.
 * 4. DedupService.scan (per-entity) uses phone_normalized for exact SQL equality,
 *    not loading all phone-bearing rows.
 */
class PhoneNormalizedTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => Role::Admin]);
    }

    // ── CompanyService ────────────────────────────────────────────────────────

    public function test_company_service_create_sets_phone_normalized(): void
    {
        /** @var CompanyService $service */
        $service = app(CompanyService::class);

        $company = $service->create([
            'name' => 'Test Co',
            'phone' => '+7 (999) 123-45-67',
        ], $this->actor);

        $this->assertSame('79991234567', $company->phone_normalized);
    }

    public function test_company_service_update_keeps_phone_normalized_in_sync(): void
    {
        /** @var CompanyService $service */
        $service = app(CompanyService::class);

        $company = Company::factory()->create([
            'owner_user_id' => $this->actor->id,
            'phone' => null,
            'phone_normalized' => null,
        ]);

        $service->update($company, ['phone' => '+77001234567'], $this->actor);
        $company->refresh();

        $this->assertSame('77001234567', $company->phone_normalized);
    }

    public function test_company_service_update_clears_phone_normalized_when_phone_removed(): void
    {
        /** @var CompanyService $service */
        $service = app(CompanyService::class);

        $company = Company::factory()->create([
            'owner_user_id' => $this->actor->id,
            'phone' => '+77001234567',
            'phone_normalized' => '77001234567',
        ]);

        $service->update($company, ['phone' => null], $this->actor);
        $company->refresh();

        $this->assertNull($company->phone_normalized);
    }

    // ── ContactService ────────────────────────────────────────────────────────

    public function test_contact_service_create_sets_phone_normalized(): void
    {
        /** @var ContactService $service */
        $service = app(ContactService::class);

        $contact = $service->create([
            'full_name' => 'Ivan Petrov',
            'phone' => '+7 (777) 888-99-00',
        ], $this->actor);

        $this->assertSame('77778889900', $contact->phone_normalized);
    }

    public function test_contact_service_update_keeps_phone_normalized_in_sync(): void
    {
        /** @var ContactService $service */
        $service = app(ContactService::class);

        $contact = Contact::factory()->create([
            'owner_id' => $this->actor->id,
            'phone' => null,
            'phone_normalized' => null,
        ]);

        $service->update($contact, ['phone' => '+77001112233']);
        $contact->refresh();

        $this->assertSame('77001112233', $contact->phone_normalized);
    }

    // ── DedupService per-entity scan ─────────────────────────────────────────

    public function test_dedup_per_entity_scan_matches_by_phone_normalized(): void
    {
        /** @var CompanyService $companyService */
        $companyService = app(CompanyService::class);
        /** @var DedupService $dedupService */
        $dedupService = app(DedupService::class);

        // Create via service so phone_normalized is populated.
        $co1 = $companyService->create([
            'name' => 'Alpha LLC',
            'phone' => '+7 (999) 111-22-33',
        ], $this->actor);

        $co2 = $companyService->create([
            'name' => 'Alpha LLC Copy',
            'phone' => '79991112233',  // same number, different format
        ], $this->actor);

        // Unique company — should NOT appear.
        $companyService->create([
            'name' => 'Unique Corp',
            'phone' => '+79990000000',
        ], $this->actor);

        $results = $dedupService->scan('company', $co1->id);

        $this->assertCount(1, $results, 'Expected exactly one duplicate candidate');
        $this->assertSame($co2->id, $results->first()->id);
    }
}
