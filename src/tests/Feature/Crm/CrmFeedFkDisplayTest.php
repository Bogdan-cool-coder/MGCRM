<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA-2026-07-04 (symmetric to Sales\DealFeedTest's FK-display coverage): the
 * Contact/Company feed's field_change track rendered raw FK ids
 * (owner_id / company_type_id / responsible_user_id / owner_user_id) instead
 * of human-readable names. Exercised through the real PATCH endpoint so the
 * whole path (Service::update → entity_logs.meta.changes → CrmFeedService)
 * is covered, not just the feed formatter.
 */
class CrmFeedFkDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_owner_change_surfaces_names_not_raw_ids_in_feed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $oldOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Ivan Petrov']);
        $newOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Anna Sidorova']);

        $contact = Contact::factory()->create(['owner_id' => $oldOwner->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", [
            'owner_id' => $newOwner->id,
        ])->assertOk();

        $feed = $this->getJson("/api/contacts/{$contact->id}/feed?types[]=field_change")->assertOk();

        $change = collect($feed->json('data.0.payload.changes'))->firstWhere('field', 'owner_id');

        $this->assertNotNull($change, 'owner_id field_change must be present');
        $this->assertSame($oldOwner->id, $change['old']);
        $this->assertSame($newOwner->id, $change['new']);
        $this->assertSame('Ivan Petrov', $change['old_display']);
        $this->assertSame('Anna Sidorova', $change['new_display']);
    }

    public function test_contact_deleted_owner_falls_back_to_hash_id_in_feed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $oldOwner = User::factory()->create(['role' => Role::Manager]);
        $newOwner = User::factory()->create(['role' => Role::Manager]);

        $contact = Contact::factory()->create(['owner_id' => $oldOwner->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/contacts/{$contact->id}", ['owner_id' => $newOwner->id])->assertOk();

        $oldOwnerId = $oldOwner->id;
        $oldOwner->delete();

        $feed = $this->getJson("/api/contacts/{$contact->id}/feed?types[]=field_change")->assertOk();
        $change = collect($feed->json('data.0.payload.changes'))->firstWhere('field', 'owner_id');

        $this->assertNotNull($change);
        $this->assertSame("#{$oldOwnerId}", $change['old_display']);
    }

    public function test_company_type_and_responsible_and_owner_change_surface_names_in_feed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $oldType = CompanyType::factory()->create(['name' => 'ООО']);
        $newType = CompanyType::factory()->create(['name' => 'АО']);
        $oldResponsible = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Old Responsible']);
        $newResponsible = User::factory()->create(['role' => Role::Manager, 'full_name' => 'New Responsible']);
        $oldOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Old Owner']);
        $newOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'New Owner']);

        $company = Company::factory()->create([
            'company_type_id' => $oldType->id,
            'responsible_user_id' => $oldResponsible->id,
            'owner_user_id' => $oldOwner->id,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/companies/{$company->id}", [
            'company_type_id' => $newType->id,
            'responsible_user_id' => $newResponsible->id,
            'owner_user_id' => $newOwner->id,
        ])->assertOk();

        $feed = $this->getJson("/api/companies/{$company->id}/feed?types[]=field_change")->assertOk();
        $changes = collect($feed->json('data.0.payload.changes'))->keyBy('field');

        $typeChange = $changes->get('company_type_id');
        $this->assertNotNull($typeChange, 'company_type_id field_change must be present');
        $this->assertSame('ООО', $typeChange['old_display']);
        $this->assertSame('АО', $typeChange['new_display']);

        $responsibleChange = $changes->get('responsible_user_id');
        $this->assertNotNull($responsibleChange, 'responsible_user_id field_change must be present');
        $this->assertSame('Old Responsible', $responsibleChange['old_display']);
        $this->assertSame('New Responsible', $responsibleChange['new_display']);

        $ownerChange = $changes->get('owner_user_id');
        $this->assertNotNull($ownerChange, 'owner_user_id field_change must be present');
        $this->assertSame('Old Owner', $ownerChange['old_display']);
        $this->assertSame('New Owner', $ownerChange['new_display']);
    }

    public function test_company_deleted_company_type_falls_back_to_hash_id_in_feed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $oldType = CompanyType::factory()->create();
        $newType = CompanyType::factory()->create();

        $company = Company::factory()->create(['company_type_id' => $oldType->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/companies/{$company->id}", ['company_type_id' => $newType->id])->assertOk();

        $oldTypeId = $oldType->id;
        $oldType->delete();

        $feed = $this->getJson("/api/companies/{$company->id}/feed?types[]=field_change")->assertOk();
        $change = collect($feed->json('data.0.payload.changes'))->firstWhere('field', 'company_type_id');

        $this->assertNotNull($change);
        $this->assertSame("#{$oldTypeId}", $change['old_display']);
    }

    public function test_non_fk_field_change_carries_no_display_keys_in_feed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create(['name' => 'Acme']);

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/companies/{$company->id}", ['name' => 'Acme Corp'])->assertOk();

        $feed = $this->getJson("/api/companies/{$company->id}/feed?types[]=field_change")->assertOk();
        $change = collect($feed->json('data.0.payload.changes'))->firstWhere('field', 'name');

        $this->assertNotNull($change);
        $this->assertArrayNotHasKey('old_display', $change);
        $this->assertArrayNotHasKey('new_display', $change);
    }
}
