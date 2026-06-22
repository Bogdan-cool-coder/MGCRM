<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ContactsKpiBarTest — covers GET /api/contacts/kpi?entity=company|contact.
 *
 * Tests:
 *  - auth required
 *  - company counters (total / clients / cat_l / cat_m / cat_s / new_week)
 *  - contact counters (total / active / no_touch_30 / new_week)
 *  - visibility scope: manager sees only own records
 *  - response shape (JSON structure)
 *  - B4: position filter on GET /api/contacts
 */
class ContactsKpiBarTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function managerActs(): User
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function adminActs(): User
    {
        $user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // =========================================================================
    // B1 — Auth gate
    // =========================================================================

    public function test_kpi_requires_authentication(): void
    {
        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertUnauthorized();
    }

    // =========================================================================
    // B1 — Company counters
    // =========================================================================

    public function test_company_kpi_total_counts_all_visible_companies(): void
    {
        $user = $this->managerActs();
        Company::factory()->count(3)->create(['owner_user_id' => $user->id]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.entity', 'company')
            ->assertJsonPath('data.total', 3);
    }

    public function test_company_kpi_clients_counts_active_client_status(): void
    {
        $user = $this->managerActs();

        // 2 active clients
        Company::factory()->count(2)->create([
            'owner_user_id' => $user->id,
            'client_status' => ClientStatus::Active,
        ]);

        // 1 prospect — should NOT be counted in clients
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'client_status' => ClientStatus::Prospect,
        ]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.clients', 2)
            ->assertJsonPath('data.total', 3);
    }

    public function test_company_kpi_cat_l_counts_category_l(): void
    {
        $user = $this->managerActs();

        Company::factory()->create(['owner_user_id' => $user->id, 'category_code' => CategoryCode::L]);
        Company::factory()->create(['owner_user_id' => $user->id, 'category_code' => CategoryCode::M]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.cat_l', 1)
            ->assertJsonPath('data.cat_m', 1);
    }

    public function test_company_kpi_cat_s_sums_s1_and_s2(): void
    {
        $user = $this->managerActs();

        Company::factory()->create(['owner_user_id' => $user->id, 'category_code' => CategoryCode::S1]);
        Company::factory()->create(['owner_user_id' => $user->id, 'category_code' => CategoryCode::S2]);
        Company::factory()->create(['owner_user_id' => $user->id, 'category_code' => CategoryCode::L]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.cat_s', 2); // S1 + S2 combined
    }

    public function test_company_kpi_new_week_counts_created_in_last_7_days(): void
    {
        $user = $this->managerActs();

        // Created today (new_week = yes)
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'created_at' => now(),
        ]);

        // Created 6 days ago (still within 7-day window)
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'created_at' => now()->subDays(6),
        ]);

        // Created 8 days ago (outside window)
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'created_at' => now()->subDays(8),
        ]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.new_week', 2);
    }

    // =========================================================================
    // B1 — Contact counters
    // =========================================================================

    public function test_contact_kpi_total_counts_all_visible_contacts(): void
    {
        $user = $this->managerActs();
        Contact::factory()->count(4)->create(['owner_id' => $user->id]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.entity', 'contact')
            ->assertJsonPath('data.total', 4);
    }

    public function test_contact_kpi_active_counts_touched_within_30_days(): void
    {
        $user = $this->managerActs();

        // Touched 15 days ago (active = yes)
        Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => now()->subDays(15),
        ]);

        // Touched 35 days ago (no_touch_30)
        Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => now()->subDays(35),
        ]);

        // Never touched (null last_activity_at → no_touch_30)
        Contact::factory()->create([
            'owner_id' => $user->id,
            'last_activity_at' => null,
        ]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.active', 1)
            ->assertJsonPath('data.no_touch_30', 2);
    }

    public function test_contact_kpi_no_touch_30_includes_null_last_activity(): void
    {
        $user = $this->managerActs();

        Contact::factory()->count(3)->create([
            'owner_id' => $user->id,
            'last_activity_at' => null,
        ]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.no_touch_30', 3);
    }

    public function test_contact_kpi_new_week_counts_created_in_last_7_days(): void
    {
        $user = $this->managerActs();

        Contact::factory()->create(['owner_id' => $user->id, 'created_at' => now()]);
        Contact::factory()->create(['owner_id' => $user->id, 'created_at' => now()->subDays(6)]);
        Contact::factory()->create(['owner_id' => $user->id, 'created_at' => now()->subDays(10)]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.new_week', 2);
    }

    // =========================================================================
    // B1 — Visibility scope
    // =========================================================================

    public function test_manager_sees_only_own_companies_in_kpi(): void
    {
        $user = $this->managerActs();
        $other = User::factory()->create(['role' => Role::Manager]);

        // Own companies
        Company::factory()->count(2)->create(['owner_user_id' => $user->id]);

        // Foreign companies (other manager's)
        Company::factory()->count(5)->create(['owner_user_id' => $other->id]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_admin_sees_all_companies_in_kpi(): void
    {
        $this->adminActs();
        $mgr1 = User::factory()->create(['role' => Role::Manager]);
        $mgr2 = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->count(2)->create(['owner_user_id' => $mgr1->id]);
        Company::factory()->count(3)->create(['owner_user_id' => $mgr2->id]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.total', 5);
    }

    public function test_manager_sees_only_own_contacts_in_kpi(): void
    {
        $user = $this->managerActs();
        $other = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->count(2)->create(['owner_id' => $user->id]);
        Contact::factory()->count(4)->create(['owner_id' => $other->id]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_admin_sees_all_contacts_in_kpi(): void
    {
        $this->adminActs();
        $mgr = User::factory()->create(['role' => Role::Manager]);

        Contact::factory()->count(6)->create(['owner_id' => $mgr->id]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonPath('data.total', 6);
    }

    // =========================================================================
    // B1 — Response shape
    // =========================================================================

    public function test_company_kpi_response_has_correct_shape(): void
    {
        $user = $this->managerActs();
        Company::factory()->create(['owner_user_id' => $user->id]);

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'entity',
                    'total',
                    'clients',
                    'cat_l',
                    'cat_m',
                    'cat_s',
                    'new_week',
                ],
            ]);
    }

    public function test_contact_kpi_response_has_correct_shape(): void
    {
        $user = $this->managerActs();
        Contact::factory()->create(['owner_id' => $user->id]);

        $this->getJson('/api/contacts/kpi?entity=contact')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'entity',
                    'total',
                    'active',
                    'no_touch_30',
                    'new_week',
                ],
            ]);
    }

    public function test_kpi_defaults_to_company_entity_when_param_missing(): void
    {
        $user = $this->managerActs();

        $this->getJson('/api/contacts/kpi')
            ->assertOk()
            ->assertJsonPath('data.entity', 'company');
    }

    // =========================================================================
    // B2 — last_activity_at in company list resource
    // =========================================================================

    public function test_company_list_includes_last_activity_at(): void
    {
        $user = $this->managerActs();
        $ts = now()->subDays(3)->startOfSecond();
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'last_activity_at' => $ts,
        ]);

        $response = $this->getJson('/api/companies')->assertOk();
        $item = $response->json('data.0');

        $this->assertArrayHasKey('last_activity_at', $item);
        $this->assertSame($ts->toIso8601String(), $item['last_activity_at']);
    }

    public function test_company_list_last_activity_at_is_null_when_never_touched(): void
    {
        $user = $this->managerActs();
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'last_activity_at' => null,
        ]);

        $response = $this->getJson('/api/companies')->assertOk();
        $this->assertNull($response->json('data.0.last_activity_at'));
    }

    // =========================================================================
    // B3 — phone in contact list resource
    // =========================================================================

    public function test_contact_list_includes_phone(): void
    {
        $user = $this->managerActs();
        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Иван Иванов',
            'phone' => '+77012345678',
        ]);

        $response = $this->getJson('/api/contacts')->assertOk();
        $item = $response->json('data.0');

        $this->assertArrayHasKey('phone', $item);
        $this->assertSame('+77012345678', $item['phone']);
    }

    public function test_contact_list_phone_can_be_null(): void
    {
        $user = $this->managerActs();
        Contact::factory()->create([
            'owner_id' => $user->id,
            'phone' => null,
        ]);

        $response = $this->getJson('/api/contacts')->assertOk();
        $this->assertNull($response->json('data.0.phone'));
    }

    // =========================================================================
    // B4 — position filter in contact list
    // =========================================================================

    public function test_position_filter_returns_matching_contacts(): void
    {
        $user = $this->managerActs();

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Директор Иванов',
            'position' => 'Генеральный директор',
        ]);

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Менеджер Петров',
            'position' => 'Менеджер по продажам',
        ]);

        $response = $this->getJson('/api/contacts?position=директор')->assertOk();

        // Only the "директор" match should be returned
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Директор Иванов', $response->json('data.0.full_name'));
    }

    public function test_position_filter_is_case_insensitive_for_latin_ascii(): void
    {
        $user = $this->managerActs();

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Тест Пользователь',
            'position' => 'CEO',
        ]);

        Contact::factory()->create([
            'owner_id' => $user->id,
            'full_name' => 'Другой Пользователь',
            'position' => 'CTO',
        ]);

        // 'ceo' lower-case search should match 'CEO'
        // SQLite LIKE is case-insensitive for ASCII by default
        $response = $this->getJson('/api/contacts?position=ceo')->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_position_filter_returns_empty_when_no_match(): void
    {
        $user = $this->managerActs();

        Contact::factory()->count(3)->create([
            'owner_id' => $user->id,
            'position' => 'Менеджер',
        ]);

        $response = $this->getJson('/api/contacts?position=Финансист')->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_position_filter_absent_returns_all_contacts(): void
    {
        $user = $this->managerActs();
        Contact::factory()->count(3)->create(['owner_id' => $user->id]);

        $response = $this->getJson('/api/contacts')->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    // =========================================================================
    // N5 graceful degradation — client_status column absent (AMO migration not applied)
    // =========================================================================

    /**
     * When the AMO N5 migration (client_status column) has NOT been applied on the
     * target environment, forCompanies() must return clients=0 instead of 500-ing.
     *
     * We simulate the "column absent" branch by partial-mocking Schema::hasColumn
     * so that it returns false for 'client_status' while returning true for any other
     * column check (preserving normal Schema behaviour for the test itself).
     */
    public function test_company_kpi_clients_returns_zero_when_client_status_column_absent(): void
    {
        $user = $this->managerActs();

        // Create a company that *would* match client_status='active' if the column existed
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'client_status' => ClientStatus::Active,
        ]);

        // Simulate the column being absent on production
        Schema::shouldReceive('hasColumn')
            ->with('crm_companies', 'client_status')
            ->once()
            ->andReturn(false);

        // All other Schema calls pass through to the real implementation
        Schema::shouldReceive('hasColumn')
            ->withAnyArgs()
            ->andReturnUsing(fn (string $table, string $col): bool => \Illuminate\Support\Facades\Schema::getFacadeRoot()->hasColumn($table, $col));

        $this->getJson('/api/contacts/kpi?entity=company')
            ->assertOk()
            ->assertJsonPath('data.clients', 0)
            ->assertJsonPath('data.total', 1); // total still works — no guard needed there
    }
}
