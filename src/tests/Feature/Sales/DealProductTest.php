<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealProductTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function setupDealAndProduct(int $priceKopecks = 500_00): array
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'currency' => 'RUB',
            'amount' => 0,
        ]);
        $product = Product::factory()->create();
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => $priceKopecks,
        ]);
        Sanctum::actingAs($user, ['*']);

        return [$deal, $product];
    }

    public function test_add_product_snapshots_price_from_catalog(): void
    {
        [$deal, $product] = $this->setupDealAndProduct(500_00);

        $this->postJson("/api/deals/{$deal->id}/products", [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.unit_price', 500_00)
            ->assertJsonPath('data.amount', 1_000_00);
    }

    public function test_add_product_with_unit_price_override(): void
    {
        [$deal, $product] = $this->setupDealAndProduct(500_00);

        $this->postJson("/api/deals/{$deal->id}/products", [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 999_00,
        ])->assertCreated()
            ->assertJsonPath('data.unit_price', 999_00)
            ->assertJsonPath('data.amount', 999_00);
    }

    public function test_deal_amount_recalculated_on_add(): void
    {
        [$deal, $product] = $this->setupDealAndProduct(300_00);

        $this->postJson("/api/deals/{$deal->id}/products", [
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertCreated();

        $deal->refresh();
        $this->assertSame(900_00, $deal->amount);
    }

    public function test_deal_amount_recalculated_on_update(): void
    {
        [$deal, $product] = $this->setupDealAndProduct(300_00);

        $line = $this->postJson("/api/deals/{$deal->id}/products", [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->json('data.id');

        $this->patchJson("/api/deals/{$deal->id}/products/{$line}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.amount', 1_200_00);

        $deal->refresh();
        $this->assertSame(1_200_00, $deal->amount);
    }

    public function test_delete_product_recalcs_amount(): void
    {
        [$deal, $product] = $this->setupDealAndProduct(300_00);

        $line = DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 300_00,
            'amount' => 600_00,
            'currency' => 'RUB',
        ]);
        $deal->update(['amount' => 600_00]);

        $this->deleteJson("/api/deals/{$deal->id}/products/{$line->id}")->assertOk();

        $deal->refresh();
        $this->assertSame(0, $deal->amount);
    }
}
