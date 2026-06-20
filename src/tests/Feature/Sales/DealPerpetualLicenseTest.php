<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Enums\BillingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Services\DealProductService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * N4 — «Вечная лицензия». Toggling Deal.perpetual_license re-prices every line
 * item between the subscription/base price and the product's perpetual price,
 * atomically. Boundary cases (product without a perpetual plan) and the N3 ×
 * N4 interaction with amount_locked are covered here.
 */
class DealPerpetualLicenseTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /**
     * Build a deal + a product that has BOTH a base price (plan_id = null) and a
     * perpetual plan with its own price, in RUB.
     *
     * @return array{0: Deal, 1: Product, 2: ProductPlan, 3: User}
     */
    private function setupPerpetualProduct(int $basePrice = 500_00, int $perpetualPrice = 5_000_00): array
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'currency' => 'RUB',
            'amount' => 0,
            'perpetual_license' => false,
        ]);

        $product = Product::factory()->create();

        // Base / subscription price (plan_id = null).
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => $basePrice,
        ]);

        // Perpetual plan + its price.
        $perpetualPlan = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Perpetual,
            'name' => 'Вечная лицензия',
        ]);
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $perpetualPlan->id,
            'currency_code' => 'RUB',
            'amount' => $perpetualPrice,
        ]);

        Sanctum::actingAs($user, ['*']);

        return [$deal, $product, $perpetualPlan, $user];
    }

    public function test_toggle_perpetual_on_reprices_lines_to_perpetual_price(): void
    {
        [$deal, $product, $perpetualPlan] = $this->setupPerpetualProduct(500_00, 5_000_00);
        $productService = app(DealProductService::class);

        // A line added on the (non-perpetual) base price.
        $line = $productService->addProduct($deal, ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertSame(500_00, $line->unit_price);
        $this->assertNull($line->plan_id);

        app(DealService::class)->update($deal, ['perpetual_license' => true]);

        $line->refresh();
        $this->assertSame(5_000_00, $line->unit_price);
        $this->assertSame($perpetualPlan->id, $line->plan_id);
        $this->assertSame(10_000_00, $line->amount); // 2 * 5_000_00

        $deal->refresh();
        $this->assertTrue($deal->perpetual_license);
        $this->assertSame(10_000_00, $deal->amount);
    }

    public function test_toggle_perpetual_off_reverts_lines_to_base_price(): void
    {
        [$deal, $product] = $this->setupPerpetualProduct(500_00, 5_000_00);
        $productService = app(DealProductService::class);
        $dealService = app(DealService::class);

        $line = $productService->addProduct($deal, ['product_id' => $product->id, 'quantity' => 2]);

        // ON: lines move to perpetual price.
        $dealService->update($deal, ['perpetual_license' => true]);
        $line->refresh();
        $this->assertSame(5_000_00, $line->unit_price);

        // OFF: lines revert to the base/subscription price (plan_id = null).
        $dealService->update($deal->fresh(), ['perpetual_license' => false]);
        $line->refresh();
        $this->assertSame(500_00, $line->unit_price);
        $this->assertNull($line->plan_id);
        $this->assertSame(1_000_00, $line->amount); // 2 * 500_00

        $deal->refresh();
        $this->assertFalse($deal->perpetual_license);
        $this->assertSame(1_000_00, $deal->amount);
    }

    public function test_toggle_keeps_manual_discount_on_reprice(): void
    {
        [$deal, $product] = $this->setupPerpetualProduct(500_00, 5_000_00);
        $productService = app(DealProductService::class);

        // qty 2 base 500_00 = gross 1_000_00, discount 200_00 -> net 800_00.
        $line = $productService->addProduct($deal, [
            'product_id' => $product->id,
            'quantity' => 2,
            'discount' => 200_00,
        ]);
        $this->assertSame(800_00, $line->amount);

        app(DealService::class)->update($deal, ['perpetual_license' => true]);

        $line->refresh();
        // gross 2 * 5_000_00 = 10_000_00, discount 200_00 survives -> net 9_800_00.
        $this->assertSame(200_00, $line->discount);
        $this->assertSame(9_800_00, $line->amount);
    }

    public function test_product_without_perpetual_plan_is_left_untouched(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'currency' => 'RUB',
            'amount' => 0,
            'perpetual_license' => false,
        ]);

        // A product with ONLY a base price — no perpetual plan.
        $product = Product::factory()->create();
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => 700_00,
        ]);
        Sanctum::actingAs($user, ['*']);

        $line = app(DealProductService::class)
            ->addProduct($deal, ['product_id' => $product->id, 'quantity' => 1]);
        $this->assertSame(700_00, $line->unit_price);

        // Toggle ON: no perpetual price exists -> line stays exactly as it was
        // (no crash, no zeroing). The toggle still flips on the deal.
        app(DealService::class)->update($deal, ['perpetual_license' => true]);

        $line->refresh();
        $this->assertSame(700_00, $line->unit_price);
        $this->assertNull($line->plan_id);
        $this->assertSame(700_00, $line->amount);

        $deal->refresh();
        $this->assertTrue($deal->perpetual_license);
        $this->assertSame(700_00, $deal->amount);
    }

    public function test_new_line_on_perpetual_deal_takes_perpetual_price(): void
    {
        [$deal, $product, $perpetualPlan] = $this->setupPerpetualProduct(500_00, 5_000_00);

        // Deal already flagged perpetual before any line is added.
        $deal->update(['perpetual_license' => true]);

        $line = app(DealProductService::class)
            ->addProduct($deal->fresh(), ['product_id' => $product->id, 'quantity' => 1]);

        $this->assertSame($perpetualPlan->id, $line->plan_id);
        $this->assertSame(5_000_00, $line->unit_price);
        $this->assertSame(5_000_00, $line->amount);
    }

    public function test_explicit_plan_id_wins_over_perpetual_flag_on_add(): void
    {
        [$deal, $product] = $this->setupPerpetualProduct(500_00, 5_000_00);

        // A separate subscription plan with its own price.
        $subPlan = ProductPlan::factory()->create([
            'product_id' => $product->id,
            'unit' => BillingUnit::Year,
        ]);
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => $subPlan->id,
            'currency_code' => 'RUB',
            'amount' => 1_200_00,
        ]);

        $deal->update(['perpetual_license' => true]);

        // Explicit plan_id must override the perpetual auto-resolution.
        $line = app(DealProductService::class)->addProduct($deal->fresh(), [
            'product_id' => $product->id,
            'plan_id' => $subPlan->id,
            'quantity' => 1,
        ]);

        $this->assertSame($subPlan->id, $line->plan_id);
        $this->assertSame(1_200_00, $line->unit_price);
    }

    public function test_locked_budget_reprices_lines_but_keeps_amount(): void
    {
        [$deal, $product] = $this->setupPerpetualProduct(500_00, 5_000_00);
        $productService = app(DealProductService::class);

        $line = $productService->addProduct($deal, ['product_id' => $product->id, 'quantity' => 1]);
        $this->assertSame(500_00, $line->unit_price);

        // Lock the budget to a fixed figure that differs from the line sum (N3).
        $deal->update(['amount_locked' => true, 'amount' => 999_999_00]);

        app(DealService::class)->update($deal->fresh(), ['perpetual_license' => true]);

        // The LINE is re-priced...
        $line->refresh();
        $this->assertSame(5_000_00, $line->unit_price);
        $this->assertSame(5_000_00, $line->amount);

        // ...but the locked Deal.amount is NOT overwritten by the new line sum.
        $deal->refresh();
        $this->assertTrue($deal->amount_locked);
        $this->assertSame(999_999_00, $deal->amount);
    }

    public function test_toggle_via_http_patch_reprices_lines(): void
    {
        [$deal, $product, $perpetualPlan] = $this->setupPerpetualProduct(500_00, 5_000_00);

        app(DealProductService::class)
            ->addProduct($deal, ['product_id' => $product->id, 'quantity' => 1]);

        $this->patchJson("/api/deals/{$deal->id}", ['perpetual_license' => true])
            ->assertOk()
            ->assertJsonPath('data.perpetual_license', true);

        $line = DealProduct::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($perpetualPlan->id, $line->plan_id);
        $this->assertSame(5_000_00, $line->unit_price);

        $deal->refresh();
        $this->assertSame(5_000_00, $deal->amount);
    }

    public function test_update_without_perpetual_change_does_not_reprice(): void
    {
        [$deal, $product] = $this->setupPerpetualProduct(500_00, 5_000_00);
        $productService = app(DealProductService::class);

        // Start perpetual, line on perpetual price.
        $deal->update(['perpetual_license' => true]);
        $line = $productService->addProduct($deal->fresh(), ['product_id' => $product->id, 'quantity' => 1]);
        $this->assertSame(5_000_00, $line->unit_price);

        // An unrelated edit (title) must NOT touch line prices.
        app(DealService::class)->update($deal->fresh(), ['title' => 'Renamed deal']);

        $line->refresh();
        $this->assertSame(5_000_00, $line->unit_price);
    }
}
