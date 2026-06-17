<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
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
}
