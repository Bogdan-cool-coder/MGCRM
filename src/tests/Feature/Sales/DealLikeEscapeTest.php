<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LIKE-escape regression coverage for DealService::applyFilters.
 *
 * The title (`q`), product name (`product_q`) and company `city` filters build
 * SQL LIKE patterns from user input and must match the value literally — the
 * wildcards `_` / `%` and a literal backslash must not leak through. Deal tags
 * use whereJsonContains (exact JSON match), not LIKE, so they already match
 * literally; the tag test below guards that exact-match behaviour against
 * regression as required by the fix scope.
 */
class DealLikeEscapeTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private Pipeline $pipeline;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = $this->seedSalesPipeline();
        $this->director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($this->director, ['*']);
    }

    private function dealOn(?Company $company = null, array $attrs = []): Deal
    {
        return Deal::factory()->forOwner($this->director)->create(array_merge([
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stageCode($this->pipeline, 'new'),
            'company_id' => ($company ?? Company::factory()->create())->id,
        ], $attrs));
    }

    /** @return list<int> */
    private function listIds(string $query): array
    {
        $response = $this->getJson('/api/deals'.$query)->assertOk();

        return array_map(static fn (array $row): int => (int) $row['id'], $response->json('data'));
    }

    public function test_title_underscore_is_not_a_wildcard(): void
    {
        $literal = $this->dealOn(null, ['title' => 'a_b']);
        $this->dealOn(null, ['title' => 'aXb']);

        $this->assertSame([$literal->id], $this->listIds('?q='.urlencode('a_b')));
    }

    public function test_title_percent_is_not_a_wildcard(): void
    {
        $literal = $this->dealOn(null, ['title' => '50%off']);
        $this->dealOn(null, ['title' => '50discountoff']);

        $this->assertSame([$literal->id], $this->listIds('?q='.urlencode('50%off')));
    }

    public function test_title_backslash_is_matched_literally(): void
    {
        $literal = $this->dealOn(null, ['title' => 'a\\b']);
        $this->dealOn(null, ['title' => 'ab']);

        $this->assertSame([$literal->id], $this->listIds('?q='.urlencode('a\\b')));
    }

    public function test_product_q_underscore_is_not_a_wildcard(): void
    {
        $literalProduct = Product::factory()->create(['name' => 'a_b']);
        $otherProduct = Product::factory()->create(['name' => 'aXb']);

        $matching = $this->dealOn();
        DealProduct::factory()->create(['deal_id' => $matching->id, 'product_id' => $literalProduct->id]);

        $other = $this->dealOn();
        DealProduct::factory()->create(['deal_id' => $other->id, 'product_id' => $otherProduct->id]);

        $this->assertSame([$matching->id], $this->listIds('?product_q='.urlencode('a_b')));
    }

    public function test_city_underscore_is_not_a_wildcard(): void
    {
        $literal = $this->dealOn(Company::factory()->create(['city' => 'a_b']));
        $this->dealOn(Company::factory()->create(['city' => 'aXb']));

        $this->assertSame([$literal->id], $this->listIds('?city='.urlencode('a_b')));
    }

    public function test_tag_underscore_is_matched_literally(): void
    {
        $literal = $this->dealOn(null, ['tags' => ['vip_lead']]);
        $this->dealOn(null, ['tags' => ['vipXlead']]);

        $this->assertSame([$literal->id], $this->listIds('?tags[]='.urlencode('vip_lead')));
    }
}
