<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CrmFeedService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA-2026-07-04 (symmetric to DealFeedServiceTest): entity_logs.meta.changes[]
 * for Contact/Company carried raw FK ids (owner_id / company_type_id /
 * responsible_user_id / owner_user_id) with no way to render them as names.
 * old_display/new_display now resolve them, batched per field.
 */
class CrmFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CrmFeedService
    {
        return app(CrmFeedService::class);
    }

    private function actingUser(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function dataChanged(LogSubjectType $subjectType, int $subjectId, array $changes, ?User $actor = null): EntityLog
    {
        return EntityLog::query()->create([
            'subject_type' => $subjectType->value,
            'subject_id' => $subjectId,
            'actor_id' => $actor?->id,
            'action' => LogAction::DataChanged->value,
            'meta' => ['changes' => $changes],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function soleFieldChangePayload(Company|Contact $entity, User $viewer): array
    {
        $event = collect($this->service()->feed($entity, $viewer)['data'])
            ->firstWhere('type', CrmFeedService::TYPE_FIELD_CHANGE);

        $this->assertNotNull($event, 'a field_change event must be present');

        return $event['payload']['changes'][0];
    }

    // ---- Contact: owner_id resolves to a user's full name ----

    public function test_contact_owner_change_resolves_to_user_full_names(): void
    {
        $viewer = $this->actingUser();
        $contact = Contact::factory()->create();
        $oldOwner = User::factory()->create(['full_name' => 'Ivan Petrov']);
        $newOwner = User::factory()->create(['full_name' => 'Anna Sidorova']);

        $this->dataChanged(LogSubjectType::Contact, (int) $contact->id, [
            ['field' => 'owner_id', 'old' => $oldOwner->id, 'new' => $newOwner->id],
        ]);

        $change = $this->soleFieldChangePayload($contact, $viewer);

        // Raw values kept for compatibility.
        $this->assertSame($oldOwner->id, $change['old']);
        $this->assertSame($newOwner->id, $change['new']);
        // Resolved human-readable names — the actual bug fix.
        $this->assertSame('Ivan Petrov', $change['old_display']);
        $this->assertSame('Anna Sidorova', $change['new_display']);
    }

    public function test_contact_null_owner_old_value_stays_null_display(): void
    {
        $viewer = $this->actingUser();
        $contact = Contact::factory()->create();
        $newOwner = User::factory()->create(['full_name' => 'Fresh Owner']);

        $this->dataChanged(LogSubjectType::Contact, (int) $contact->id, [
            ['field' => 'owner_id', 'old' => null, 'new' => $newOwner->id],
        ]);

        $change = $this->soleFieldChangePayload($contact, $viewer);

        $this->assertNull($change['old_display']);
        $this->assertSame('Fresh Owner', $change['new_display']);
    }

    public function test_contact_deleted_owner_target_degrades_to_hash_id(): void
    {
        $viewer = $this->actingUser();
        $contact = Contact::factory()->create();
        $deletedId = $viewer->id + 999_999; // never persisted

        $this->dataChanged(LogSubjectType::Contact, (int) $contact->id, [
            ['field' => 'owner_id', 'old' => null, 'new' => $deletedId],
        ]);

        $change = $this->soleFieldChangePayload($contact, $viewer);

        $this->assertNull($change['old_display']);
        $this->assertSame("#{$deletedId}", $change['new_display']);
    }

    public function test_contact_non_fk_field_change_carries_no_display_keys(): void
    {
        $viewer = $this->actingUser();
        $contact = Contact::factory()->create();

        $this->dataChanged(LogSubjectType::Contact, (int) $contact->id, [
            ['field' => 'full_name', 'old' => 'Old Name', 'new' => 'New Name'],
        ]);

        $change = $this->soleFieldChangePayload($contact, $viewer);

        $this->assertArrayNotHasKey('old_display', $change);
        $this->assertArrayNotHasKey('new_display', $change);
    }

    // ---- Company: company_type_id / responsible_user_id / owner_user_id ----

    public function test_company_type_change_resolves_to_company_type_names(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $oldType = CompanyType::factory()->create(['name' => 'ООО']);
        $newType = CompanyType::factory()->create(['name' => 'АО']);

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'company_type_id', 'old' => $oldType->id, 'new' => $newType->id],
        ]);

        $change = $this->soleFieldChangePayload($company, $viewer);

        $this->assertSame('ООО', $change['old_display']);
        $this->assertSame('АО', $change['new_display']);
    }

    public function test_company_responsible_user_change_resolves_to_user_full_names(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $oldUser = User::factory()->create(['full_name' => 'Ivan Petrov']);
        $newUser = User::factory()->create(['full_name' => 'Anna Sidorova']);

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'responsible_user_id', 'old' => $oldUser->id, 'new' => $newUser->id],
        ]);

        $change = $this->soleFieldChangePayload($company, $viewer);

        $this->assertSame('Ivan Petrov', $change['old_display']);
        $this->assertSame('Anna Sidorova', $change['new_display']);
    }

    public function test_company_owner_user_change_resolves_to_user_full_names(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $oldOwner = User::factory()->create(['full_name' => 'Old Owner']);
        $newOwner = User::factory()->create(['full_name' => 'New Owner']);

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'owner_user_id', 'old' => $oldOwner->id, 'new' => $newOwner->id],
        ]);

        $change = $this->soleFieldChangePayload($company, $viewer);

        $this->assertSame('Old Owner', $change['old_display']);
        $this->assertSame('New Owner', $change['new_display']);
    }

    public function test_company_deleted_fk_target_degrades_to_hash_id(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $newType = CompanyType::factory()->create();
        $deletedId = $newType->id + 999_999; // never persisted

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'company_type_id', 'old' => null, 'new' => $deletedId],
        ]);

        $change = $this->soleFieldChangePayload($company, $viewer);

        $this->assertNull($change['old_display']);
        $this->assertSame("#{$deletedId}", $change['new_display']);
    }

    public function test_company_non_fk_field_change_carries_no_display_keys(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'name', 'old' => 'Acme', 'new' => 'Acme Corp'],
        ]);

        $change = $this->soleFieldChangePayload($company, $viewer);

        $this->assertArrayNotHasKey('old_display', $change);
        $this->assertArrayNotHasKey('new_display', $change);
    }

    // ---- Batched resolution: one whereIn query per FK field, never per-row ----

    public function test_fk_resolution_uses_bounded_query_count_regardless_of_row_count(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $owners = User::factory()->count(5)->create();

        // 20 owner_user_id changes referencing only 5 distinct users — resolution
        // must issue ONE whereIn query for the User model, not one per row
        // (mirrors DealFeedServiceTest's equivalent assertion).
        foreach (range(1, 20) as $i) {
            $owner = $owners[$i % 5];
            $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
                ['field' => 'owner_user_id', 'old' => null, 'new' => $owner->id],
            ]);
        }

        DB::enableQueryLog();
        $result = $this->service()->feed($company, $viewer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(20, $result['data']);

        $userSelects = collect($queries)->filter(
            fn (array $q): bool => str_contains((string) $q['query'], 'select') && str_contains((string) $q['query'], '"users"'),
        );

        $this->assertLessThanOrEqual(1, $userSelects->count());
    }

    public function test_mixed_fk_fields_each_resolve_with_their_own_batched_query(): void
    {
        $viewer = $this->actingUser();
        $company = Company::factory()->create();
        $type = CompanyType::factory()->create(['name' => 'ТОО']);
        $responsible = User::factory()->create(['full_name' => 'Responsible Person']);
        $owner = User::factory()->create(['full_name' => 'Owner Person']);

        $this->dataChanged(LogSubjectType::Company, (int) $company->id, [
            ['field' => 'company_type_id', 'old' => null, 'new' => $type->id],
            ['field' => 'responsible_user_id', 'old' => null, 'new' => $responsible->id],
            ['field' => 'owner_user_id', 'old' => null, 'new' => $owner->id],
        ]);

        $event = collect($this->service()->feed($company, $viewer)['data'])
            ->firstWhere('type', CrmFeedService::TYPE_FIELD_CHANGE);

        $changes = collect($event['payload']['changes'])->keyBy('field');

        $this->assertSame('ТОО', $changes['company_type_id']['new_display']);
        $this->assertSame('Responsible Person', $changes['responsible_user_id']['new_display']);
        $this->assertSame('Owner Person', $changes['owner_user_id']['new_display']);
    }
}
