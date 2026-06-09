<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Company;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PromotionController (Documents section, M3):
 *   - index / show: company-scoped, any role with company access reads
 *   - store / update / destroy: admin (company) + superadmin write; analyst /
 *     viewer read-only (403)
 *   - cross-company isolation (other company's promotion invisible / unwritable)
 *   - validation: discount_min <= discount_max, percent <= 100
 */
class PromotionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    // -------------------------------------------------------------------------
    // index / show — read access + company scoping
    // -------------------------------------------------------------------------

    /** @test */
    public function test_index_lists_only_active_company_promotions(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $admin = $this->makeUser($companyA, 'admin');

        $own   = Promotion::factory()->create(['company_id' => $companyA->id]);
        $other = Promotion::factory()->create(['company_id' => $companyB->id]);

        $response = $this->actingAs($admin)->getJson('/api/promotions');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function test_index_active_filter(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $active   = Promotion::factory()->create(['company_id' => $company->id]);
        $inactive = Promotion::factory()->inactive()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson('/api/promotions?active=1');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    /** @test */
    public function test_viewer_and_analyst_can_read_promotions(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $analyst = $this->makeUser($company, 'analyst');
        $promo   = Promotion::factory()->create(['company_id' => $company->id]);

        $this->actingAs($viewer)->getJson('/api/promotions')->assertOk();
        $this->actingAs($analyst)->getJson("/api/promotions/{$promo->id}")->assertOk()
            ->assertJsonPath('id', $promo->id)
            ->assertJsonPath('discount_type', Promotion::TYPE_PERCENT);
    }

    /** @test */
    public function test_show_of_other_company_promotion_is_forbidden(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $admin = $this->makeUser($companyA, 'admin');
        $other = Promotion::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($admin)->getJson("/api/promotions/{$other->id}")->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // store — write ACL + validation
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_create_promotion(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $response = $this->actingAs($admin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'Весна', 'en' => 'Spring'],
            'discount_type' => 'percent',
            'discount_min'  => 0,
            'discount_max'  => 15,
        ]);

        // discount_max is a float in the payload; JSON serializes a whole float
        // as 15 (no .0), so assert against the integer-equal value.
        $response->assertStatus(201)
            ->assertJsonPath('discount_max', 15)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('created_by', $admin->id);

        $this->assertDatabaseHas('promotions', [
            'company_id'    => $company->id,
            'discount_type' => 'percent',
        ]);
    }

    /** @test */
    public function test_superadmin_can_create_for_active_company(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');

        $this->actingAs($superadmin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'absolute',
            'discount_min'  => 1000,
            'discount_max'  => 50000,
        ])->assertStatus(201)->assertJsonPath('company_id', $company->id);
    }

    /** @test */
    public function test_analyst_cannot_create_promotion(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'percent',
            'discount_min'  => 0,
            'discount_max'  => 10,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('promotions', ['company_id' => $company->id]);
    }

    /** @test */
    public function test_viewer_cannot_create_promotion(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'percent',
            'discount_min'  => 0,
            'discount_max'  => 10,
        ])->assertStatus(403);
    }

    /** @test */
    public function test_create_rejects_min_greater_than_max(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $this->actingAs($admin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'percent',
            'discount_min'  => 20,
            'discount_max'  => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount_min']);
    }

    /** @test */
    public function test_create_rejects_percent_over_100(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $this->actingAs($admin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'percent',
            'discount_min'  => 0,
            'discount_max'  => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount_max']);
    }

    /** @test */
    public function test_create_allows_absolute_over_100(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        // 100 is a percent cap only — absolute amounts can be arbitrarily large.
        $this->actingAs($admin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'absolute',
            'discount_min'  => 0,
            'discount_max'  => 200000,
        ])->assertStatus(201);
    }

    /** @test */
    public function test_create_rejects_invalid_discount_type(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $this->actingAs($admin)->postJson('/api/promotions', [
            'name'          => ['ru' => 'X'],
            'discount_type' => 'freebie',
            'discount_min'  => 0,
            'discount_max'  => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount_type']);
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_update_promotion(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $promo = Promotion::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/promotions/{$promo->id}", [
            'discount_max' => 25,
            'is_active'    => false,
        ])->assertOk()
            ->assertJsonPath('discount_max', 25)
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('promotions', [
            'id'           => $promo->id,
            'discount_max' => 25,
            'is_active'    => false,
        ]);
    }

    /** @test */
    public function test_update_validates_min_max_against_persisted_state(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        // Stored min=5; raising max above-min would be fine, but lowering max
        // below the persisted min must fail even though only max is supplied.
        $promo = Promotion::factory()->create([
            'company_id'   => $company->id,
            'discount_min' => 5,
            'discount_max' => 20,
        ]);

        $this->actingAs($admin)->putJson("/api/promotions/{$promo->id}", [
            'discount_max' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount_min']);
    }

    /** @test */
    public function test_analyst_cannot_update_promotion(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $promo = Promotion::factory()->create(['company_id' => $company->id]);

        $this->actingAs($analyst)->putJson("/api/promotions/{$promo->id}", [
            'discount_max' => 99,
        ])->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_update_other_company_promotion(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $admin = $this->makeUser($companyA, 'admin');
        $other = Promotion::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($admin)->putJson("/api/promotions/{$other->id}", [
            'discount_max' => 99,
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_delete_promotion(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $promo = Promotion::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->deleteJson("/api/promotions/{$promo->id}")->assertOk();
        $this->assertDatabaseMissing('promotions', ['id' => $promo->id]);
    }

    /** @test */
    public function test_viewer_cannot_delete_promotion(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $promo = Promotion::factory()->create(['company_id' => $company->id]);

        $this->actingAs($viewer)->deleteJson("/api/promotions/{$promo->id}")->assertStatus(403);
        $this->assertDatabaseHas('promotions', ['id' => $promo->id]);
    }

    /** @test */
    public function test_admin_cannot_delete_other_company_promotion(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $admin = $this->makeUser($companyA, 'admin');
        $other = Promotion::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($admin)->deleteJson("/api/promotions/{$other->id}")->assertStatus(403);
        $this->assertDatabaseHas('promotions', ['id' => $other->id]);
    }
}
