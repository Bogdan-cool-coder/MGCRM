<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The CRM feed surfaces the action log's data_changed rows as "field_change"
 * items so the «Изменения» feed filter has real content for CRM entities (the
 * audit's dead-chip fix — CrmFeedService previously emitted only activities).
 */
class CrmFeedFieldChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_feed_includes_field_change_from_action_log(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create(['name' => 'Acme']);

        // A logged field change is what populates the field_change feed track.
        app(CompanyService::class)->update($company, ['name' => 'Acme Corp'], $user);

        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed");

        $response->assertOk();

        $items = $response->json('data');
        $fieldChanges = array_values(array_filter(
            $items,
            static fn (array $i): bool => $i['type'] === 'field_change',
        ));

        $this->assertCount(1, $fieldChanges, 'feed should surface one field_change row');

        $changes = $fieldChanges[0]['payload']['changes'];
        $this->assertSame('name', $changes[0]['field']);
        $this->assertSame('Acme', $changes[0]['old']);
        $this->assertSame('Acme Corp', $changes[0]['new']);
        $this->assertSame($user->id, $fieldChanges[0]['actor']['id']);
    }

    public function test_company_feed_field_change_filter_excludes_activities(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create(['name' => 'Acme']);

        app(CompanyService::class)->update($company, ['name' => 'Acme Corp'], $user);

        // types[]=field_change must return only the change row (chip is now live).
        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson("/api/companies/{$company->id}/feed?types[]=field_change");

        $response->assertOk();

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame('field_change', $item['type']);
        }
    }

    // ── field_label: raw column names render as human-readable RU labels ──────

    public function test_company_feed_change_carries_human_readable_field_label(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create(['name' => 'Acme']);

        app(CompanyService::class)->update($company, ['name' => 'Acme Corp'], $user);

        Sanctum::actingAs($user, ['*']);
        $change = $this->getJson("/api/companies/{$company->id}/feed")
            ->assertOk()
            ->json('data.0.payload.changes.0');

        // Raw field kept for compatibility; label is the human-readable RU string.
        $this->assertSame('name', $change['field']);
        $this->assertSame('Название', $change['field_label']);
    }

    public function test_contact_feed_change_carries_human_readable_field_label(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $contact = Contact::factory()->create(['full_name' => 'Ivan']);

        app(ContactService::class)->update($contact, ['full_name' => 'Ivan Petrov'], $user);

        Sanctum::actingAs($user, ['*']);
        $change = $this->getJson("/api/contacts/{$contact->id}/feed")
            ->assertOk()
            ->json('data.0.payload.changes.0');

        $this->assertSame('full_name', $change['field']);
        $this->assertSame('ФИО', $change['field_label']);
    }

    public function test_company_feed_unknown_field_falls_back_to_humanized_label(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        // Seed the action log directly with a field that has no label mapping —
        // the resolver must humanize it, never crash, never echo snake_case.
        EntityLog::query()->create([
            'subject_type' => LogSubjectType::Company->value,
            'subject_id' => $company->id,
            'actor_id' => $user->id,
            'action' => LogAction::DataChanged->value,
            'meta' => ['changes' => [['field' => 'weird_new_field', 'old' => 'a', 'new' => 'b']]],
        ]);

        Sanctum::actingAs($user, ['*']);
        $change = $this->getJson("/api/companies/{$company->id}/feed")
            ->assertOk()
            ->json('data.0.payload.changes.0');

        $this->assertSame('weird_new_field', $change['field']);
        $this->assertSame('Weird new field', $change['field_label']);
    }

    public function test_company_feed_custom_field_resolves_to_def_label(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        $company = Company::factory()->create();

        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => 'amo_cf_500100',
            'label' => 'Отрасль',
            'field_type' => 'text',
            'is_active' => true,
        ]);

        EntityLog::query()->create([
            'subject_type' => LogSubjectType::Company->value,
            'subject_id' => $company->id,
            'actor_id' => $user->id,
            'action' => LogAction::DataChanged->value,
            'meta' => ['changes' => [['field' => 'extra_fields.amo_cf_500100', 'old' => null, 'new' => 'IT']]],
        ]);

        Sanctum::actingAs($user, ['*']);
        $change = $this->getJson("/api/companies/{$company->id}/feed")
            ->assertOk()
            ->json('data.0.payload.changes.0');

        $this->assertSame('Отрасль', $change['field_label']);
    }
}
