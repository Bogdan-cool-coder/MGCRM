<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LIKE-escape regression coverage for ContactService::list.
 *
 * The tag filter and the free-text search/position filters build SQL LIKE
 * patterns from user input. Without escaping, the LIKE wildcards `_` (any single
 * char) and `%` (any run) — and a literal backslash — leak through and the value
 * silently behaves as a wildcard (e.g. tag `vip_lead` would also match
 * `vipXlead`). These tests prove the value is matched LITERALLY.
 */
class ContactLikeEscapeTest extends TestCase
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
        $response = $this->getJson('/api/contacts'.$query)->assertOk();

        return array_map(static fn (array $row): int => (int) $row['id'], $response->json('data'));
    }

    public function test_tag_underscore_is_not_a_wildcard(): void
    {
        $literal = Contact::factory()->create(['tags' => ['vip_lead']]);
        Contact::factory()->create(['tags' => ['vipXlead']]); // _ would match X — must NOT

        $this->assertSame([$literal->id], $this->listIds('?tags[]='.urlencode('vip_lead')));
    }

    public function test_tag_percent_is_not_a_wildcard(): void
    {
        $literal = Contact::factory()->create(['tags' => ['50%off']]);
        Contact::factory()->create(['tags' => ['50discountoff']]); // % would match "discount"

        $this->assertSame([$literal->id], $this->listIds('?tags[]='.urlencode('50%off')));
    }

    public function test_search_underscore_is_not_a_wildcard(): void
    {
        $literal = Contact::factory()->create(['full_name' => 'a_b']);
        Contact::factory()->create(['full_name' => 'aXb']);

        $this->assertSame([$literal->id], $this->listIds('?search='.urlencode('a_b')));
    }

    public function test_search_backslash_is_matched_literally(): void
    {
        // Plain-text column (not JSON): the backslash is stored verbatim, so the
        // ESCAPE '\' clause + backslash escaping must match it literally.
        $literal = Contact::factory()->create(['full_name' => 'a\\b']);
        Contact::factory()->create(['full_name' => 'ab']);

        $this->assertSame([$literal->id], $this->listIds('?search='.urlencode('a\\b')));
    }

    public function test_position_underscore_is_not_a_wildcard(): void
    {
        $literal = Contact::factory()->create(['position' => 'a_b']);
        Contact::factory()->create(['position' => 'aXb']);

        $this->assertSame([$literal->id], $this->listIds('?position='.urlencode('a_b')));
    }
}
