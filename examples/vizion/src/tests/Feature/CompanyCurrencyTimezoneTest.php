<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the currency_code + timezone columns on companies
 * (DEVELOPMENT_PLAN_CAPITALDATA.md §3.3.A / §3.3.B).
 *
 * Covers:
 *  - POST /api/companies accepts and persists both fields
 *  - PUT  /api/companies/{id} updates both fields
 *  - GET  /api/companies/{id} returns both fields in the payload
 *  - GET  /api/user nests active_company with both fields
 *  - validation: currency_code must be exactly 3 chars (ISO 4217 shape);
 *    timezone is capped at 64 chars
 *
 * Tests run against sqlite :memory: — see tests/TestCase.php createApplication()
 * guard. The migration default ('RUB' / 'Europe/Moscow') means rows created
 * without explicit values will be backfilled by the DB layer; we assert the
 * explicit-values path here, not the default path.
 */
class CompanyCurrencyTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperadmin(Company $company): User
    {
        return User::factory()->create([
            'company_id'       => $company->id,
            'role'             => 'superadmin',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
    }

    private function baseCompanyPayload(string $name): array
    {
        return [
            'name'               => $name,
            'crm_url'            => 'https://crm.test',
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
        ];
    }

    /** @test */
    public function test_store_persists_currency_code_and_timezone(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $payload = $this->baseCompanyPayload('Acme Ltd') + [
            'currency_code' => 'USD',
            'timezone'      => 'Europe/London',
        ];

        $response = $this->actingAs($admin)->postJson('/api/companies', $payload);

        $response->assertCreated()
            ->assertJsonPath('currency_code', 'USD')
            ->assertJsonPath('timezone', 'Europe/London');

        $this->assertDatabaseHas('companies', [
            'name'          => 'Acme Ltd',
            'currency_code' => 'USD',
            'timezone'      => 'Europe/London',
        ]);
    }

    /** @test */
    public function test_update_modifies_currency_code_and_timezone(): void
    {
        $home   = Company::create($this->baseCompanyPayload('Home'));
        $target = Company::create($this->baseCompanyPayload('Target') + [
            'currency_code' => 'RUB',
            'timezone'      => 'Europe/Moscow',
        ]);
        $admin  = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->putJson("/api/companies/{$target->id}", [
            'currency_code' => 'KZT',
            'timezone'      => 'Asia/Almaty',
        ]);

        $response->assertOk()
            ->assertJsonPath('currency_code', 'KZT')
            ->assertJsonPath('timezone', 'Asia/Almaty');

        $this->assertSame('KZT', $target->fresh()->currency_code);
        $this->assertSame('Asia/Almaty', $target->fresh()->timezone);
    }

    /** @test */
    public function test_show_returns_currency_code_and_timezone(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home') + [
            'currency_code' => 'KZT',
            'timezone'      => 'Asia/Almaty',
        ]);
        $admin = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->getJson("/api/companies/{$home->id}");

        $response->assertOk()
            ->assertJsonPath('currency_code', 'KZT')
            ->assertJsonPath('timezone', 'Asia/Almaty');
    }

    /** @test */
    public function test_get_user_exposes_active_company_currency_and_timezone(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home') + [
            'currency_code' => 'UZS',
            'timezone'      => 'Asia/Tashkent',
        ]);
        $admin = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('active_company.id', $home->id)
            ->assertJsonPath('active_company.currency_code', 'UZS')
            ->assertJsonPath('active_company.timezone', 'Asia/Tashkent')
            ->assertJsonPath('company.currency_code', 'UZS')
            ->assertJsonPath('company.timezone', 'Asia/Tashkent');
    }

    /** @test */
    public function test_store_rejects_currency_code_longer_than_three_chars(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $payload = $this->baseCompanyPayload('Bad Ltd') + [
            'currency_code' => 'USDD', // 4 chars — invalid ISO 4217
        ];

        $response = $this->actingAs($admin)->postJson('/api/companies', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);

        $this->assertDatabaseMissing('companies', ['name' => 'Bad Ltd']);
    }

    /** @test */
    public function test_store_rejects_currency_code_shorter_than_three_chars(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $payload = $this->baseCompanyPayload('Bad Ltd') + [
            'currency_code' => 'US', // 2 chars — invalid
        ];

        $response = $this->actingAs($admin)->postJson('/api/companies', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);
    }

    /** @test */
    public function test_update_rejects_timezone_longer_than_64_chars(): void
    {
        $home   = Company::create($this->baseCompanyPayload('Home'));
        $target = Company::create($this->baseCompanyPayload('Target'));
        $admin  = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->putJson("/api/companies/{$target->id}", [
            'timezone' => str_repeat('a', 65),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    /** @test */
    public function test_store_allows_null_currency_and_timezone(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $payload = $this->baseCompanyPayload('Sysco') + [
            'currency_code' => null,
            'timezone'      => null,
        ];

        $response = $this->actingAs($admin)->postJson('/api/companies', $payload);

        $response->assertCreated()
            ->assertJsonPath('currency_code', null)
            ->assertJsonPath('timezone', null);
    }

    // ------------------------------------------------------------------
    // Role-based update access (Tech debt §1).
    // ------------------------------------------------------------------

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id'       => $company->id,
            'role'             => $role,
            'company_accesses' => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    /** @test */
    public function test_admin_can_update_currency_and_timezone_for_own_company(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeUser($home, 'admin');

        $response = $this->actingAs($admin)->putJson("/api/companies/{$home->id}", [
            'currency_code' => 'EUR',
            'timezone'      => 'Europe/Berlin',
        ]);

        $response->assertOk()
            ->assertJsonPath('currency_code', 'EUR')
            ->assertJsonPath('timezone', 'Europe/Berlin');
    }

    /** @test */
    public function test_admin_cannot_update_other_company(): void
    {
        $home   = Company::create($this->baseCompanyPayload('Home'));
        $other  = Company::create($this->baseCompanyPayload('Other'));
        $admin  = $this->makeUser($home, 'admin');

        $response = $this->actingAs($admin)->putJson("/api/companies/{$other->id}", [
            'currency_code' => 'EUR',
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function test_analyst_cannot_update_company(): void
    {
        $home    = Company::create($this->baseCompanyPayload('Home'));
        $analyst = $this->makeUser($home, 'analyst');

        $response = $this->actingAs($analyst)->putJson("/api/companies/{$home->id}", [
            'currency_code' => 'EUR',
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function test_viewer_cannot_update_company(): void
    {
        $home   = Company::create($this->baseCompanyPayload('Home'));
        $viewer = $this->makeUser($home, 'viewer');

        $response = $this->actingAs($viewer)->putJson("/api/companies/{$home->id}", [
            'currency_code' => 'EUR',
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function test_update_rejects_unknown_iana_timezone(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->putJson("/api/companies/{$home->id}", [
            'timezone' => 'Europe/Atlantis', // not a real IANA identifier
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    /** @test */
    public function test_update_rejects_lowercase_currency_code(): void
    {
        $home  = Company::create($this->baseCompanyPayload('Home'));
        $admin = $this->makeSuperadmin($home);

        $response = $this->actingAs($admin)->putJson("/api/companies/{$home->id}", [
            'currency_code' => 'usd',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency_code']);
    }
}
