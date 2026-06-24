<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
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
}
