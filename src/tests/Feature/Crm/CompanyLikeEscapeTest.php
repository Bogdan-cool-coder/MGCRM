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
 * LIKE-escape regression coverage for CompanyService::list.
 *
 * Mirrors ContactLikeEscapeTest: the tag, free-text search, and city filters
 * build LIKE patterns from user input and must match the value literally — the
 * wildcards `_` / `%` and a literal backslash must not leak through.
 */
class CompanyLikeEscapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    /** @return list<int> */
    private function listIds(string $query): array
    {
        $response = $this->getJson('/api/companies'.$query)->assertOk();

        return array_map(static fn (array $row): int => (int) $row['id'], $response->json('data'));
    }

    public function test_tag_underscore_is_not_a_wildcard(): void
    {
        $literal = Company::factory()->create(['tags' => ['vip_lead']]);
        Company::factory()->create(['tags' => ['vipXlead']]);

        $this->assertSame([$literal->id], $this->listIds('?tags[]='.urlencode('vip_lead')));
    }

    public function test_tag_percent_is_not_a_wildcard(): void
    {
        $literal = Company::factory()->create(['tags' => ['50%off']]);
        Company::factory()->create(['tags' => ['50discountoff']]);

        $this->assertSame([$literal->id], $this->listIds('?tags[]='.urlencode('50%off')));
    }

    public function test_search_underscore_is_not_a_wildcard(): void
    {
        $literal = Company::factory()->create(['name' => 'a_b']);
        Company::factory()->create(['name' => 'aXb']);

        $this->assertSame([$literal->id], $this->listIds('?search='.urlencode('a_b')));
    }

    public function test_search_backslash_is_matched_literally(): void
    {
        // Plain-text column (not JSON): the backslash is stored verbatim, so the
        // ESCAPE '\' clause + backslash escaping must match it literally.
        $literal = Company::factory()->create(['name' => 'a\\b']);
        Company::factory()->create(['name' => 'ab']);

        $this->assertSame([$literal->id], $this->listIds('?search='.urlencode('a\\b')));
    }

    public function test_city_underscore_is_not_a_wildcard(): void
    {
        $literal = Company::factory()->create(['city' => 'a_b']);
        Company::factory()->create(['city' => 'aXb']);

        $this->assertSame([$literal->id], $this->listIds('?city='.urlencode('a_b')));
    }
}
