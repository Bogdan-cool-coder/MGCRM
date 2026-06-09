<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMacrodataMapping;
use App\Models\User;
use App\Services\MacroData\CompanySchemaProbeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for /api/companies/{company}/macrodata-mappings.
 *
 * Covers:
 *  - GET returns mappings of the target company only (no cross-company leak)
 *  - GET / PUT / DELETE are forbidden for analyst & viewer
 *  - PUT performs bulk upsert in a transaction (existing rows updated,
 *    missing rows untouched)
 *  - PUT rejects malformed semantic_keys
 *  - DELETE removes a single mapping, 404 when absent
 *  - Cross-company ACL: admin of company A cannot touch B
 *  - Superadmin can write to any company
 *  - Probe endpoint delegates to CompanySchemaProbeService and shapes the
 *    response as {data: {probed_at, mappings, unresolved}}; service-level
 *    failures translate to 503 with a localised message (no leaked PDO details)
 *
 * Runs against sqlite :memory: (see tests/TestCase.php guard).
 */
class CompanyMacrodataMappingTest extends TestCase
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

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    /** @test */
    public function test_index_returns_only_target_company_mappings(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $admin = $this->makeUser($home, 'superadmin');

        CompanyMacrodataMapping::create([
            'company_id'   => $home->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);
        CompanyMacrodataMapping::create([
            'company_id'   => $other->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [9999], // must NOT leak into Home's response
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/companies/{$home->id}/macrodata-mappings");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.semantic_key', 'finance_type_sale_ids')
            ->assertJsonPath('data.0.value', [3786]);
    }

    /** @test */
    public function test_index_orders_by_semantic_key(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'superadmin');

        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [1],
        ]);
        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_booking_ids',
            'value'        => [2],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/companies/{$company->id}/macrodata-mappings");

        $response->assertOk()
            ->assertJsonPath('data.0.semantic_key', 'finance_type_booking_ids')
            ->assertJsonPath('data.1.semantic_key', 'finance_type_sale_ids');
    }

    /** @test */
    public function test_index_forbidden_for_analyst(): void
    {
        $company = $this->makeCompany('Co');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)
            ->getJson("/api/companies/{$company->id}/macrodata-mappings")
            ->assertForbidden();
    }

    /** @test */
    public function test_index_forbidden_for_viewer(): void
    {
        $company = $this->makeCompany('Co');
        $viewer  = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)
            ->getJson("/api/companies/{$company->id}/macrodata-mappings")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // update (bulk upsert)
    // ------------------------------------------------------------------

    /** @test */
    public function test_update_creates_new_mappings(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $payload = [
            'mappings' => [
                ['semantic_key' => 'finance_type_sale_ids',    'value' => [3786], 'notes' => 'manual'],
                ['semantic_key' => 'finance_type_booking_ids', 'value' => [3788], 'notes' => null],
            ],
        ];

        $response = $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", $payload);

        $response->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('company_macrodata_mappings', [
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'notes'        => 'manual',
        ]);
        $this->assertDatabaseHas('company_macrodata_mappings', [
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_booking_ids',
        ]);
    }

    /** @test */
    public function test_update_upserts_existing_row(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $existing = CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [1, 2, 3],
            'notes'        => 'auto',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [3786], 'notes' => 'manual'],
                ],
            ])
            ->assertOk();

        $existing->refresh();
        $this->assertSame([3786], $existing->value);
        $this->assertSame('manual', $existing->notes);

        // Same primary key — really upsert, not delete-and-recreate.
        $this->assertSame(1, CompanyMacrodataMapping::where('company_id', $company->id)->count());
    }

    /** @test */
    public function test_update_is_partial_does_not_delete_unmentioned_keys(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_booking_ids',
            'value'        => [3788],
        ]);

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [3786]],
                ],
            ])
            ->assertOk();

        // Booking key was not in the payload — must survive.
        $this->assertDatabaseHas('company_macrodata_mappings', [
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_booking_ids',
        ]);
        $this->assertSame(2, CompanyMacrodataMapping::where('company_id', $company->id)->count());
    }

    /** @test */
    public function test_update_rejects_invalid_semantic_key_with_spaces(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'has spaces', 'value' => [1]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mappings.0.semantic_key']);
    }

    /** @test */
    public function test_update_rejects_uppercase_semantic_key(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'FinanceTypeSaleIds', 'value' => [1]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mappings.0.semantic_key']);
    }

    /** @test */
    public function test_update_rejects_semantic_key_starting_with_digit(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => '1bad_key', 'value' => [1]],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mappings.0.semantic_key']);
    }

    /** @test */
    public function test_update_requires_value_field_to_be_present(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids'], // value missing
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mappings.0.value']);
    }

    /** @test */
    public function test_update_allows_scalar_value(): void
    {
        // Future semantic_keys may resolve to a single int / string, not an
        // array — the validator must accept scalars.
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'some_scalar_key', 'value' => 42],
                ],
            ])
            ->assertOk();

        $stored = CompanyMacrodataMapping::where('company_id', $company->id)
            ->where('semantic_key', 'some_scalar_key')->firstOrFail();
        $this->assertSame(42, $stored->value);
    }

    /** @test */
    public function test_update_forbidden_for_analyst(): void
    {
        $company = $this->makeCompany('Co');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [3786]],
                ],
            ])
            ->assertForbidden();
    }

    /** @test */
    public function test_admin_cannot_update_other_company_mappings(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $admin = $this->makeUser($home, 'admin');

        $this->actingAs($admin)
            ->putJson("/api/companies/{$other->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [9999]],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('company_macrodata_mappings', [
            'company_id' => $other->id,
        ]);
    }

    /** @test */
    public function test_superadmin_can_update_any_company(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $superadmin = $this->makeUser($home, 'superadmin');

        $this->actingAs($superadmin)
            ->putJson("/api/companies/{$other->id}/macrodata-mappings", [
                'mappings' => [
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [3786]],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('company_macrodata_mappings', [
            'company_id'   => $other->id,
            'semantic_key' => 'finance_type_sale_ids',
        ]);
    }

    /** @test */
    public function test_bulk_upsert_accepts_auto_probed_at(): void
    {
        // Probe-apply flow: the frontend sends `auto_probed_at` so the row is
        // visibly marked as probe-sourced. Round-trip through the GET response
        // proves the timestamp landed in the DB and serialised back cleanly.
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $probedAt = '2026-05-21T10:00:00+00:00';

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    [
                        'semantic_key'   => 'finance_type_sale_ids',
                        'value'          => [3786],
                        'auto_probed_at' => $probedAt,
                    ],
                ],
            ])
            ->assertOk();

        $stored = CompanyMacrodataMapping::where('company_id', $company->id)
            ->where('semantic_key', 'finance_type_sale_ids')->firstOrFail();

        $this->assertNotNull($stored->auto_probed_at);
        $this->assertTrue(
            Carbon::parse($probedAt)->equalTo($stored->auto_probed_at),
            'auto_probed_at must round-trip through the API'
        );

        // The serialised GET response surfaces it under the same key.
        $this->actingAs($admin)
            ->getJson("/api/companies/{$company->id}/macrodata-mappings")
            ->assertOk()
            ->assertJsonPath(
                'data.0.auto_probed_at',
                Carbon::parse($probedAt)->toIso8601String()
            );
    }

    /** @test */
    public function test_bulk_upsert_omitting_auto_probed_at_preserves_existing(): void
    {
        // Manual inline edits from the UI do NOT send `auto_probed_at`. The
        // probe timestamp must survive a partial upsert.
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $existing = CompanyMacrodataMapping::create([
            'company_id'     => $company->id,
            'semantic_key'   => 'finance_type_sale_ids',
            'value'          => [3786],
            'auto_probed_at' => Carbon::parse('2026-05-21T09:00:00+00:00'),
        ]);

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    // No auto_probed_at — pure manual edit.
                    ['semantic_key' => 'finance_type_sale_ids', 'value' => [3786, 3787], 'notes' => 'edited'],
                ],
            ])
            ->assertOk();

        $existing->refresh();

        $this->assertSame([3786, 3787], $existing->value);
        $this->assertSame('edited', $existing->notes);
        // The timestamp set by the earlier probe is untouched.
        $this->assertNotNull($existing->auto_probed_at);
        $this->assertTrue(
            Carbon::parse('2026-05-21T09:00:00+00:00')->equalTo($existing->auto_probed_at)
        );
    }

    /** @test */
    public function test_bulk_upsert_explicit_null_auto_probed_at_clears(): void
    {
        // Sending an explicit `null` is the documented way to clear the
        // probe-sourced flag (e.g. operator switched a previously probed row
        // to a manual value and wants the "—" display back).
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $existing = CompanyMacrodataMapping::create([
            'company_id'     => $company->id,
            'semantic_key'   => 'finance_type_sale_ids',
            'value'          => [3786],
            'auto_probed_at' => Carbon::parse('2026-05-21T09:00:00+00:00'),
        ]);

        $this->actingAs($admin)
            ->putJson("/api/companies/{$company->id}/macrodata-mappings", [
                'mappings' => [
                    [
                        'semantic_key'   => 'finance_type_sale_ids',
                        'value'          => [3786],
                        'auto_probed_at' => null,
                    ],
                ],
            ])
            ->assertOk();

        $existing->refresh();
        $this->assertNull($existing->auto_probed_at);
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    /** @test */
    public function test_destroy_removes_single_mapping(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/companies/{$company->id}/macrodata-mappings/finance_type_sale_ids")
            ->assertNoContent();

        $this->assertDatabaseMissing('company_macrodata_mappings', [
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
        ]);
    }

    /** @test */
    public function test_destroy_returns_404_when_key_absent(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)
            ->deleteJson("/api/companies/{$company->id}/macrodata-mappings/finance_type_sale_ids")
            ->assertNotFound();
    }

    /** @test */
    public function test_destroy_forbidden_for_analyst(): void
    {
        $company = $this->makeCompany('Co');
        $analyst = $this->makeUser($company, 'analyst');

        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);

        $this->actingAs($analyst)
            ->deleteJson("/api/companies/{$company->id}/macrodata-mappings/finance_type_sale_ids")
            ->assertForbidden();
    }

    /** @test */
    public function test_destroy_cross_company_forbidden_for_admin(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $admin = $this->makeUser($home, 'admin');

        CompanyMacrodataMapping::create([
            'company_id'   => $other->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [9999],
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/companies/{$other->id}/macrodata-mappings/finance_type_sale_ids")
            ->assertForbidden();

        $this->assertDatabaseHas('company_macrodata_mappings', [
            'company_id' => $other->id,
        ]);
    }

    // ------------------------------------------------------------------
    // probe (delegates to CompanySchemaProbeService)
    // ------------------------------------------------------------------

    /**
     * Swap CompanySchemaProbeService in the container for a mock whose `probe()`
     * returns the supplied value (or throws when an exception is passed). We
     * intentionally bind via `$this->app->instance(...)` instead of using
     * `Mockery::mock(...)->shouldReceive(...)` directly so method injection in
     * the controller resolves the mock cleanly.
     */
    private function mockProbeService(array|\Throwable $resultOrException): void
    {
        $mock = Mockery::mock(CompanySchemaProbeService::class);

        if ($resultOrException instanceof \Throwable) {
            $mock->shouldReceive('probe')->andThrow($resultOrException);
        } else {
            $mock->shouldReceive('probe')->andReturn($resultOrException);
        }

        $this->app->instance(CompanySchemaProbeService::class, $mock);
    }

    /** @test */
    public function test_probe_returns_proposal_shape(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $probedAt = Carbon::parse('2026-05-21T12:34:56+00:00');

        $this->mockProbeService([
            'probed_at' => $probedAt,
            'mappings'  => [
                [
                    'semantic_key' => 'finance_type_sale_ids',
                    'value'        => [3786],
                    'matched_by'   => "RU: '%Поступления от продажи%'",
                    'candidates'   => [
                        ['id' => 3786, 'name' => 'Поступления от продажи недвижимости'],
                    ],
                ],
            ],
            'unresolved' => ['finance_type_booking_ids'],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/macrodata-mappings/probe");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'probed_at',
                    'mappings' => [
                        '*' => ['semantic_key', 'value', 'matched_by', 'candidates'],
                    ],
                    'unresolved',
                ],
            ])
            ->assertJsonPath('data.probed_at', $probedAt->toIso8601String())
            ->assertJsonPath('data.mappings.0.semantic_key', 'finance_type_sale_ids')
            ->assertJsonPath('data.mappings.0.value', [3786])
            ->assertJsonPath('data.unresolved', ['finance_type_booking_ids']);
    }

    /** @test */
    public function test_probe_forbidden_for_analyst(): void
    {
        $company = $this->makeCompany('Co');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)
            ->postJson("/api/companies/{$company->id}/macrodata-mappings/probe")
            ->assertForbidden();
    }

    /** @test */
    public function test_probe_forbidden_for_viewer(): void
    {
        $company = $this->makeCompany('Co');
        $viewer  = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)
            ->postJson("/api/companies/{$company->id}/macrodata-mappings/probe")
            ->assertForbidden();
    }

    /** @test */
    public function test_probe_returns_503_when_service_throws(): void
    {
        $company = $this->makeCompany('Co');
        $admin   = $this->makeUser($company, 'admin');

        $this->mockProbeService(new \RuntimeException('Could not connect to MacroData replica at 10.0.0.5:3306'));

        $response = $this->actingAs($admin)
            ->postJson("/api/companies/{$company->id}/macrodata-mappings/probe");

        $response->assertStatus(503)
            ->assertJsonStructure(['error', 'message'])
            ->assertJsonPath('error', 'macrodata_unavailable');

        // Make sure the raw exception message (with host:port) is NOT leaked in
        // the response — those details belong only in server logs.
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent() ?: '');
    }

    // ------------------------------------------------------------------
    // auth
    // ------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_returns_401(): void
    {
        $company = $this->makeCompany('Co');

        $this->getJson("/api/companies/{$company->id}/macrodata-mappings")
            ->assertStatus(401);

        $this->putJson("/api/companies/{$company->id}/macrodata-mappings", ['mappings' => []])
            ->assertStatus(401);

        $this->deleteJson("/api/companies/{$company->id}/macrodata-mappings/finance_type_sale_ids")
            ->assertStatus(401);

        $this->postJson("/api/companies/{$company->id}/macrodata-mappings/probe")
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Company model helpers
    // ------------------------------------------------------------------

    /** @test */
    public function test_company_macrodata_value_returns_default_for_missing_key(): void
    {
        $company = $this->makeCompany('Co');

        $this->assertSame([], $company->macrodataValue('absent_key', []));
        $this->assertNull($company->macrodataValue('absent_key'));
    }

    /** @test */
    public function test_company_macrodata_value_returns_stored_value(): void
    {
        $company = $this->makeCompany('Co');
        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);

        $this->assertSame([3786], $company->macrodataValue('finance_type_sale_ids'));
    }

    /** @test */
    public function test_company_macrodata_value_caches_after_first_read(): void
    {
        // Sanity check on the instance-level cache: multiple lookups inside a
        // single request must not hit the DB more than once. We verify this by
        // mutating the underlying row directly via the DB and confirming the
        // model still returns the cached value.
        $company = $this->makeCompany('Co');
        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);

        // First read primes the cache.
        $this->assertSame([3786], $company->macrodataValue('finance_type_sale_ids'));

        // Bypass the model layer to mutate the row, simulating a stale cache.
        CompanyMacrodataMapping::where('company_id', $company->id)
            ->update(['value' => json_encode([9999])]);

        // Second read should still return the cached value.
        $this->assertSame([3786], $company->macrodataValue('finance_type_sale_ids'));

        // A fresh model instance reads the new value.
        $this->assertSame([9999], $company->fresh()->macrodataValue('finance_type_sale_ids'));
    }

    /** @test */
    public function test_company_macrodata_mappings_as_array_returns_flat_map(): void
    {
        $company = $this->makeCompany('Co');
        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_sale_ids',
            'value'        => [3786],
        ]);
        CompanyMacrodataMapping::create([
            'company_id'   => $company->id,
            'semantic_key' => 'finance_type_booking_ids',
            'value'        => [3788],
        ]);

        $map = $company->macrodataMappingsAsArray();

        $this->assertSame([3786], $map['finance_type_sale_ids']);
        $this->assertSame([3788], $map['finance_type_booking_ids']);
        $this->assertCount(2, $map);
    }
}
