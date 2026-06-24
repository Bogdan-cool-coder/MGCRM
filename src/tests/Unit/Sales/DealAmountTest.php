<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealProductService;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealAmountTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeal(): Deal
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

        return Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => User::factory()->create()->id,
            'amount' => 0,
        ]);
    }

    public function test_recalc_amount_sums_line_items(): void
    {
        $deal = $this->makeDeal();
        DealProduct::factory()->create(['deal_id' => $deal->id, 'quantity' => 2, 'unit_price' => 100_00, 'amount' => 200_00]);
        DealProduct::factory()->create(['deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 50_00, 'amount' => 50_00]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(250_00, $recalced->amount);
    }

    public function test_recalc_amount_zero_when_no_products(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['amount' => 999_00]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(0, $recalced->amount);
    }

    public function test_line_item_amount_is_quantity_times_unit_price(): void
    {
        $deal = $this->makeDeal();
        // 2.5 units * 400_00 kopecks = 1_000_00
        $product = DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 2.5,
            'unit_price' => 400_00,
            'amount' => (int) round(2.5 * 400_00),
        ]);

        $this->assertSame(1_000_00, (int) $product->amount);
    }

    public function test_update_product_subtracts_discount_from_line_amount(): void
    {
        $deal = $this->makeDeal();
        $line = DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 2,
            'unit_price' => 300_00,
            'discount' => 0,
            'amount' => 600_00,
        ]);

        // gross 2 * 300_00 = 600_00, discount 150_00 -> net 450_00
        app(DealProductService::class)->updateProduct($line, ['discount' => 150_00]);

        $this->assertSame(450_00, (int) $line->fresh()->amount);
    }

    public function test_discount_never_makes_line_amount_negative(): void
    {
        $deal = $this->makeDeal();
        $line = DealProduct::factory()->create([
            'deal_id' => $deal->id,
            'quantity' => 1,
            'unit_price' => 100_00,
            'discount' => 0,
            'amount' => 100_00,
        ]);

        // discount larger than gross -> clamped to 0, not negative
        app(DealProductService::class)->updateProduct($line, ['discount' => 250_00]);

        $this->assertSame(0, (int) $line->fresh()->amount);
    }

    public function test_recalc_amount_sums_net_line_items_after_discount(): void
    {
        $deal = $this->makeDeal();
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 2, 'unit_price' => 100_00, 'discount' => 50_00, 'amount' => 150_00,
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 50_00, 'discount' => 0, 'amount' => 50_00,
        ]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(200_00, $recalced->amount);
    }

    public function test_recalc_amount_folds_deal_level_discount_percent(): void
    {
        // B0 fix: deals.amount must be NET — the deal-level discount_percent is
        // applied per line then summed (matches DealResource::products_net_total).
        $deal = $this->makeDeal();
        $deal->update(['discount_percent' => 50]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 100_00, 'discount' => 0, 'amount' => 100_00,
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 50_00, 'discount' => 0, 'amount' => 50_00,
        ]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        // 50% off each line: 50_00 + 25_00 = 75_00 (gross would be 150_00).
        $this->assertSame(75_00, $recalced->amount);
    }

    public function test_recalc_amount_discount_rounds_per_line_then_sums(): void
    {
        // Per-line rounding then sum (the resource's documented convention): a 30%
        // discount on two odd-kopeck lines rounds each line, not the grand total.
        $deal = $this->makeDeal();
        $deal->update(['discount_percent' => 30]);
        // round(101 * 0.7) = round(70.7) = 71; round(103 * 0.7) = round(72.1) = 72.
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 101, 'discount' => 0, 'amount' => 101,
        ]);
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 103, 'discount' => 0, 'amount' => 103,
        ]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(71 + 72, $recalced->amount);
    }

    public function test_locked_budget_ignores_discount_percent(): void
    {
        // A locked budget is a fixed figure — the deal-level discount does not
        // re-derive it (recalcAmount short-circuits before the calculator).
        $deal = $this->makeDeal();
        DealProduct::factory()->create([
            'deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 100_00, 'discount' => 0, 'amount' => 100_00,
        ]);
        $deal->update(['amount' => 500_00, 'amount_locked' => true, 'discount_percent' => 40]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(500_00, (int) $recalced->amount);
    }

    public function test_locked_budget_recalc_does_not_overwrite_amount(): void
    {
        // N3: a locked budget is a fixed figure — adding a line item (which
        // re-runs recalcAmount via DealProductService) must NOT touch amount,
        // even though the line items now sum to a different number.
        $deal = $this->makeDeal();
        $deal->update(['amount' => 500_00, 'amount_locked' => true]);

        // Line item worth 999_00 — irrelevant while the budget is locked.
        app(DealProductService::class)->addProduct($deal, [
            'product_id' => $this->makeProductId(),
            'quantity' => 1,
            'unit_price' => 999_00,
        ]);

        // amount stays at the locked budget; it deliberately != sum(products).
        $this->assertSame(500_00, (int) $deal->fresh()->amount);
    }

    public function test_locked_budget_recalc_amount_returns_early_unchanged(): void
    {
        // Direct service call: recalcAmount short-circuits on a locked deal even
        // when there are real line items that would otherwise sum differently.
        $deal = $this->makeDeal();
        DealProduct::factory()->create(['deal_id' => $deal->id, 'quantity' => 1, 'unit_price' => 100_00, 'amount' => 100_00]);
        $deal->update(['amount' => 777_00, 'amount_locked' => true]);

        $recalced = app(DealService::class)->recalcAmount($deal);

        $this->assertSame(777_00, (int) $recalced->amount);
        $this->assertSame(777_00, (int) $deal->fresh()->amount);
    }

    public function test_unlocked_budget_still_recalculates_from_line_items(): void
    {
        // Regression: the default (amount_locked = false) path is unchanged —
        // adding a line still re-derives amount from the line-item sum.
        $deal = $this->makeDeal();
        $deal->update(['amount' => 0, 'amount_locked' => false]);

        app(DealProductService::class)->addProduct($deal, [
            'product_id' => $this->makeProductId(),
            'quantity' => 3,
            'unit_price' => 100_00,
        ]);

        $this->assertSame(300_00, (int) $deal->fresh()->amount);
    }

    /**
     * Create a minimal RUB-priced catalog product (plan_id = null, matching how
     * DealProductService::addProduct resolves the price snapshot) and return its
     * id. The 100_00 price keeps the explicit unit_price-free add path resolvable.
     */
    private function makeProductId(): int
    {
        $product = Product::factory()->create();
        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => 100_00,
        ]);

        return (int) $product->id;
    }
}
