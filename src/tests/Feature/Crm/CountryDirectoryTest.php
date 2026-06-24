<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Country;
use App\Domain\Crm\Services\CountryService;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for:
 *   - CountryDirectory CRUD (admin-only writes, any-auth reads)
 *   - active_only query param for dropdown selects
 *   - code uniqueness validation on store
 *   - code immutability on update (code field silently ignored)
 *   - delete guard: 422 when country_code is still referenced by companies or cities
 *   - CountryService.list() returns correct rows
 *   - Migration seed idempotence (kz/uz/ru/ae present)
 */
class CountryDirectoryTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // READ — list / show (open to any authenticated user; CRM-5)
    // =========================================================================

    public function test_manager_can_list_countries(): void
    {
        // CRM-5: directory READS are open to any authenticated user (the country
        // catalog feeds filter dropdowns + the country column label).
        Country::create(['code' => 'fr', 'name' => 'Frantsiya', 'sort_order' => 10, 'is_active' => true]);

        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/admin/countries')->assertOk();
    }

    public function test_admin_can_list_all_countries_without_filter(): void
    {
        Country::create(['code' => 'fr', 'name' => 'Frantsiya', 'sort_order' => 10, 'is_active' => true]);
        Country::create(['code' => 'de', 'name' => 'Germaniya', 'sort_order' => 11, 'is_active' => false]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/countries')->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->toArray();
        $this->assertContains('fr', $codes);
        $this->assertContains('de', $codes); // inactive also returned without filter
    }

    public function test_active_only_filter_excludes_inactive(): void
    {
        Country::create(['code' => 'fr', 'name' => 'Frantsiya', 'sort_order' => 10, 'is_active' => true]);
        Country::create(['code' => 'de', 'name' => 'Germaniya', 'sort_order' => 11, 'is_active' => false]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/countries?active_only=1')->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->toArray();
        $this->assertContains('fr', $codes);
        $this->assertNotContains('de', $codes);
    }

    public function test_show_returns_country_resource(): void
    {
        $country = Country::create([
            'code' => 'gb',
            'name' => 'Velikobritaniya',
            'name_en' => 'United Kingdom',
            'phone_prefix' => '+44',
            'sort_order' => 20,
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/countries/{$country->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'gb')
            ->assertJsonPath('data.name', 'Velikobritaniya')
            ->assertJsonPath('data.phone_prefix', '+44');
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    public function test_admin_can_create_country(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/countries', [
            'code' => 'tr',
            'name' => 'Turtsiya',
            'name_en' => 'Turkey',
            'phone_prefix' => '+90',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.code', 'tr')
            ->assertJsonPath('data.name', 'Turtsiya')
            ->assertJsonPath('data.phone_prefix', '+90');

        $this->assertDatabaseHas('crm_countries', ['code' => 'tr', 'name' => 'Turtsiya']);
    }

    public function test_director_can_create_country(): void
    {
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/admin/countries', [
            'code' => 'pl',
            'name' => 'Polsha',
        ])->assertSuccessful();
    }

    public function test_manager_cannot_create_country(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/countries', [
            'code' => 'it',
            'name' => 'Italiya',
        ])->assertForbidden();
    }

    public function test_store_validates_code_uniqueness(): void
    {
        // 'kz' is already seeded by the countries migration — posting it again
        // must fail validation with a uniqueness error.
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/countries', [
            'code' => 'kz',
            'name' => 'Duplicate Kazakhstan',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('code');
    }

    public function test_store_validates_code_must_be_2_chars(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/countries', [
            'code' => 'kzz',
            'name' => 'Bad code',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('code');
    }

    public function test_store_requires_name(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/countries', ['code' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('name');
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function test_admin_can_update_country_name_and_active(): void
    {
        $country = Country::create(['code' => 'by', 'name' => 'Belarus', 'sort_order' => 6]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/admin/countries/{$country->id}", [
            'name' => 'Belarus Updated',
            'is_active' => false,
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'Belarus Updated')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('crm_countries', [
            'id' => $country->id,
            'name' => 'Belarus Updated',
            'is_active' => 0,
        ]);
    }

    public function test_update_does_not_change_code(): void
    {
        // Even if the client sends 'code', the UpdateCountryRequest ignores it.
        $country = Country::create(['code' => 'lt', 'name' => 'Litva', 'sort_order' => 7]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/admin/countries/{$country->id}", [
            'code' => 'xx', // should be silently ignored
            'name' => 'Litva Updated',
        ])->assertSuccessful()
            ->assertJsonPath('data.code', 'lt'); // code unchanged

        $this->assertDatabaseHas('crm_countries', ['id' => $country->id, 'code' => 'lt']);
        $this->assertDatabaseMissing('crm_countries', ['code' => 'xx']);
    }

    public function test_manager_cannot_update_country(): void
    {
        $country = Country::create(['code' => 'lv', 'name' => 'Latviya', 'sort_order' => 8]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->patchJson("/api/admin/countries/{$country->id}", [
            'name' => 'Lat Updated',
        ])->assertForbidden();
    }

    // =========================================================================
    // DELETE — happy path
    // =========================================================================

    public function test_admin_can_delete_unreferenced_country(): void
    {
        $country = Country::create(['code' => 'ee', 'name' => 'Estonia', 'sort_order' => 99]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/countries/{$country->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('crm_countries', ['id' => $country->id]);
    }

    public function test_manager_cannot_delete_country(): void
    {
        $country = Country::create(['code' => 'sk', 'name' => 'Slovakia', 'sort_order' => 99]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->deleteJson("/api/admin/countries/{$country->id}")
            ->assertForbidden();
    }

    // =========================================================================
    // DELETE — guard: referenced by company
    // =========================================================================

    public function test_delete_blocked_when_company_references_country(): void
    {
        $country = Country::create(['code' => 'sg', 'name' => 'Singapore', 'sort_order' => 50]);

        $user = User::factory()->create(['role' => Role::Manager]);
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'country_code' => 'sg',
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/countries/{$country->id}")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('crm_countries', ['id' => $country->id]);
    }

    // =========================================================================
    // DELETE — guard: referenced by city
    // =========================================================================

    public function test_delete_blocked_when_city_references_country(): void
    {
        $country = Country::create(['code' => 'hr', 'name' => 'Croatia', 'sort_order' => 55]);

        DB::table('crm_cities')->insert([
            'country_code' => 'hr',
            'name' => 'Zagreb',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson("/api/admin/countries/{$country->id}")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('crm_countries', ['id' => $country->id]);
    }

    // =========================================================================
    // CountryService — unit-level
    // =========================================================================

    public function test_service_list_returns_all_by_default(): void
    {
        Country::create(['code' => 'fi', 'name' => 'Finland', 'sort_order' => 1, 'is_active' => true]);
        Country::create(['code' => 'no', 'name' => 'Norway',  'sort_order' => 2, 'is_active' => false]);

        $service = app(CountryService::class);
        $all = $service->list(activeOnly: false);

        $codes = $all->pluck('code')->toArray();
        $this->assertContains('fi', $codes);
        $this->assertContains('no', $codes);
    }

    public function test_service_list_active_only(): void
    {
        Country::create(['code' => 'fi', 'name' => 'Finland', 'sort_order' => 1, 'is_active' => true]);
        Country::create(['code' => 'no', 'name' => 'Norway',  'sort_order' => 2, 'is_active' => false]);

        $service = app(CountryService::class);
        $active = $service->list(activeOnly: true);

        $codes = $active->pluck('code')->toArray();
        $this->assertContains('fi', $codes);
        $this->assertNotContains('no', $codes);
    }

    public function test_service_delete_guard_throws_for_referenced_company(): void
    {
        $country = Country::create(['code' => 'nz', 'name' => 'New Zealand', 'sort_order' => 60]);
        $user = User::factory()->create(['role' => Role::Manager]);
        Company::factory()->create([
            'owner_user_id' => $user->id,
            'country_code' => 'nz',
        ]);

        $service = app(CountryService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/active company/i');

        $service->delete($country);
    }

    public function test_service_delete_unreferenced_country_succeeds(): void
    {
        $country = Country::create(['code' => 'is', 'name' => 'Iceland', 'sort_order' => 70]);

        $service = app(CountryService::class);
        $result = $service->delete($country);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('crm_countries', ['id' => $country->id]);
    }

    // =========================================================================
    // Migration seed idempotence
    // =========================================================================

    public function test_migration_seeded_kz_and_uz(): void
    {
        // These are seeded in the initial migration (2026_06_11_110003).
        // RefreshDatabase runs all migrations so they must be present.
        $this->assertDatabaseHas('crm_countries', ['code' => 'kz']);
        $this->assertDatabaseHas('crm_countries', ['code' => 'uz']);
    }

    public function test_migration_seeded_ru_and_ae(): void
    {
        // Seeded in the additive migration (2026_06_30_100000).
        $this->assertDatabaseHas('crm_countries', ['code' => 'ru']);
        $this->assertDatabaseHas('crm_countries', ['code' => 'ae']);
    }

    public function test_country_resource_shape(): void
    {
        $country = Country::create([
            'code' => 'jp',
            'name' => 'Japan',
            'name_en' => 'Japan',
            'phone_prefix' => '+81',
            'sort_order' => 25,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/countries/{$country->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'code', 'name', 'name_en',
                    'phone_prefix', 'sort_order', 'is_active',
                ],
            ]);
    }
}
