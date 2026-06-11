<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Services\DedupService;
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
}
