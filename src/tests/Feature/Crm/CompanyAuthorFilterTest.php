<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for author_ids[] filter on the company list endpoint.
 *
 * GAP-3: frontend sends author_ids[] for companies, but backend was ignoring it.
 * Fix: IndexCompanyRequest now accepts author_ids[] and CompanyService::list()
 * applies whereIn('crm_companies.created_by_id', $authorIds) as AND-logic.
 *
 * Mirrors the equivalent Contact author_ids tests (Wave 2a).
 */
class CompanyAuthorFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Admin sees all companies regardless of visibility scope.
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    // =========================================================================
    // author_ids[] — single author
    // =========================================================================

    public function test_author_ids_filters_single_author(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $other  = User::factory()->create(['role' => Role::Manager]);

        $match   = Company::factory()->create(['created_by_id' => $author->id]);
        $noMatch = Company::factory()->create(['created_by_id' => $other->id]);

        $response = $this->getJson('/api/companies?author_ids[]='.$author->id)->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($noMatch->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // author_ids[] — multiple authors (OR within the filter)
    // =========================================================================

    public function test_author_ids_filters_multiple_authors(): void
    {
        $a1    = User::factory()->create(['role' => Role::Manager]);
        $a2    = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);

        $c1      = Company::factory()->create(['created_by_id' => $a1->id]);
        $c2      = Company::factory()->create(['created_by_id' => $a2->id]);
        $noMatch = Company::factory()->create(['created_by_id' => $other->id]);

        $response = $this->getJson("/api/companies?author_ids[]={$a1->id}&author_ids[]={$a2->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertNotContains($noMatch->id, $ids);
        $this->assertCount(2, $ids);
    }

    // =========================================================================
    // author_ids[] + owner_ids[] — AND intersection
    // =========================================================================

    public function test_author_ids_combined_with_owner_ids_is_and_intersection(): void
    {
        $author = User::factory()->create(['role' => Role::Manager]);
        $owner  = User::factory()->create(['role' => Role::Manager]);
        $other  = User::factory()->create(['role' => Role::Manager]);

        // This company matches both author_ids AND owner_ids — should appear.
        $match = Company::factory()->create([
            'created_by_id' => $author->id,
            'owner_user_id' => $owner->id,
        ]);

        // Matches author but not owner — should be excluded.
        Company::factory()->create([
            'created_by_id' => $author->id,
            'owner_user_id' => $other->id,
        ]);

        // Matches owner but not author — should be excluded.
        Company::factory()->create([
            'created_by_id' => $other->id,
            'owner_user_id' => $owner->id,
        ]);

        $response = $this->getJson(
            "/api/companies?author_ids[]={$author->id}&owner_ids[]={$owner->id}"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // author_ids[] absent — no filter applied (all companies returned)
    // =========================================================================

    public function test_no_author_ids_returns_all_companies(): void
    {
        $a1 = User::factory()->create(['role' => Role::Manager]);
        $a2 = User::factory()->create(['role' => Role::Manager]);

        Company::factory()->create(['created_by_id' => $a1->id]);
        Company::factory()->create(['created_by_id' => $a2->id]);

        $response = $this->getJson('/api/companies')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // =========================================================================
    // author_ids[] — unknown/non-existent user id returns empty result
    // =========================================================================

    public function test_author_ids_unknown_user_returns_empty(): void
    {
        Company::factory()->create(['created_by_id' => $this->admin->id]);

        $response = $this->getJson('/api/companies?author_ids[]=99999')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }
}
