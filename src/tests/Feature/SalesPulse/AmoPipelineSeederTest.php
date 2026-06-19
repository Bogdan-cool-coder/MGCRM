<?php

declare(strict_types=1);

namespace Tests\Feature\SalesPulse;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealService;
use App\Domain\SalesPulse\Data\StageMeta;
use App\Domain\SalesPulse\Services\StageClassificationService;
use Database\Seeders\AmoPipelineSeeder;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AmoPipelineSeeder — mirrors the TWO real AMO funnels ("MACRO Global" /
 * "MACRO AI Global") into MGCRM 1-for-1 for the SalesPulse bot. Verifies the
 * funnels build with the right stages/order/flags/colours, the seeder is
 * idempotent, the locked "Продажи" funnel is untouched, StageClassificationService
 * classifies the new stages correctly, and StageMeta resolves the new codes.
 */
class AmoPipelineSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Expected MACRO Global stage codes in AMO order. */
    private const MACRO_GLOBAL_CODES = [
        'unsorted', 'partner', 'outbound', 'inbound', 'qualification',
        'schedule', 'walking', 'meeting', 'cold', 'warm', 'trial', 'hot',
        'success', 'lost',
    ];

    /** Expected MACRO AI Global codes — no partner (→ long_term), no walking. */
    private const MACRO_AI_GLOBAL_CODES = [
        'unsorted', 'long_term', 'outbound', 'inbound', 'qualification',
        'schedule', 'meeting', 'cold', 'warm', 'trial', 'hot',
        'success', 'lost',
    ];

    private function pipeline(string $name): Pipeline
    {
        return Pipeline::where('name', $name)->where('kind', PipelineKind::Sales->value)->firstOrFail();
    }

    /**
     * @return list<string> stage codes ordered by sort_order
     */
    private function orderedCodes(string $name): array
    {
        return PipelineStage::where('pipeline_id', $this->pipeline($name)->id)
            ->orderBy('sort_order')
            ->pluck('code')
            ->all();
    }

    // -------------------------------------------------------------------------
    // funnels + stages build
    // -------------------------------------------------------------------------

    public function test_both_funnels_are_created_as_sales_pipelines(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $this->assertSame(PipelineKind::Sales, $this->pipeline('MACRO Global')->kind);
        $this->assertSame(PipelineKind::Sales, $this->pipeline('MACRO AI Global')->kind);
    }

    public function test_macro_global_has_14_stages_in_amo_order(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $this->assertSame(self::MACRO_GLOBAL_CODES, $this->orderedCodes('MACRO Global'));
    }

    public function test_macro_ai_global_has_13_stages_without_partner_and_walking(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $codes = $this->orderedCodes('MACRO AI Global');

        $this->assertSame(self::MACRO_AI_GLOBAL_CODES, $codes);
        $this->assertNotContains('partner', $codes);
        $this->assertNotContains('walking', $codes);
        $this->assertContains('long_term', $codes);
    }

    public function test_stage_names_and_colors_match_amo(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $global = PipelineStage::where('pipeline_id', $this->pipeline('MACRO Global')->id)
            ->get()->keyBy('code');

        $this->assertSame('Неразобранное', $global['unsorted']->name);
        $this->assertSame('партнерский канал', $global['partner']->name);
        $this->assertSame('4.1. walking', $global['walking']->name);
        $this->assertSame('#c1c1c1', $global['unsorted']->color);
        $this->assertSame('#fff000', $global['qualification']->color);
        $this->assertSame('#fff000', $global['cold']->color);
        $this->assertSame('#CCFF66', $global['success']->color);

        $ai = PipelineStage::where('pipeline_id', $this->pipeline('MACRO AI Global')->id)
            ->get()->keyBy('code');

        // AI Global recolours qualification / cold / warm vs MACRO Global.
        $this->assertSame('В долгосрочной перспективе', $ai['long_term']->name);
        $this->assertSame('#ccc8f9', $ai['long_term']->color);
        $this->assertSame('#ffff99', $ai['qualification']->color);
        $this->assertSame('#fffeb2', $ai['cold']->color);
        $this->assertSame('#ffdc7f', $ai['warm']->color);
    }

    public function test_won_lost_cold_flags_on_both_funnels(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        foreach (['MACRO Global', 'MACRO AI Global'] as $name) {
            $stages = PipelineStage::where('pipeline_id', $this->pipeline($name)->id)
                ->get()->keyBy('code');

            $this->assertTrue($stages['success']->is_won, "$name success.is_won");
            $this->assertFalse($stages['success']->is_lost);

            $this->assertTrue($stages['lost']->is_lost, "$name lost.is_lost");
            $this->assertFalse($stages['lost']->is_won);

            $this->assertTrue($stages['cold']->hidden_by_default, "$name cold.hidden_by_default");
            $this->assertFalse($stages['cold']->is_won);
            $this->assertFalse($stages['cold']->is_lost);

            // No await_payment/paid carried over from the "Продажи" funnel.
            $this->assertArrayNotHasKey('await_payment', $stages->all());
            $this->assertArrayNotHasKey('paid', $stages->all());
        }
    }

    // -------------------------------------------------------------------------
    // idempotency + isolation
    // -------------------------------------------------------------------------

    public function test_re_running_does_not_duplicate(): void
    {
        $this->seed(AmoPipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);

        $this->assertSame(1, Pipeline::where('name', 'MACRO Global')->count());
        $this->assertSame(1, Pipeline::where('name', 'MACRO AI Global')->count());
        $this->assertCount(14, $this->orderedCodes('MACRO Global'));
        $this->assertCount(13, $this->orderedCodes('MACRO AI Global'));
    }

    public function test_locked_prodazhi_stages_are_untouched_but_funnel_is_archived(): void
    {
        // Seed the locked funnel first, then the AMO funnels.
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);

        $prodazhi = Pipeline::where('name', 'Продажи')->firstOrFail();

        $this->assertSame(1, Pipeline::where('name', 'Продажи')->count());
        $this->assertSame(
            11,
            PipelineStage::where('pipeline_id', $prodazhi->id)->count(),
            'Продажи must keep its 11 locked stages (archive is reversible)',
        );

        // Reversibly archived: deactivated + pushed to the tail, stages intact.
        $this->assertFalse($prodazhi->is_active, 'Продажи must be archived (is_active=false)');
        $this->assertSame(2, $prodazhi->sort_order, 'Продажи sorts after both AMO funnels');

        // The AMO funnels are separate pipeline rows.
        $this->assertNotSame($prodazhi->id, $this->pipeline('MACRO Global')->id);
        $this->assertNotSame($prodazhi->id, $this->pipeline('MACRO AI Global')->id);
        $this->assertSame(3, Pipeline::sales()->count());
    }

    public function test_amo_funnels_are_active_and_macro_global_sorts_first(): void
    {
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);

        $global = $this->pipeline('MACRO Global');
        $ai = $this->pipeline('MACRO AI Global');

        $this->assertTrue($global->is_active);
        $this->assertSame(0, $global->sort_order, 'MACRO Global is the default (lowest sort_order)');

        $this->assertTrue($ai->is_active);
        $this->assertSame(1, $ai->sort_order);
    }

    public function test_default_active_sales_pipeline_is_macro_global(): void
    {
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);

        // The default = first ACTIVE sales pipeline by sort_order.
        $default = Pipeline::query()
            ->sales()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $this->assertNotNull($default);
        $this->assertSame('MACRO Global', $default->name);

        // The archived "Продажи" funnel must never be the default even though it
        // carries no sort_order penalty among ALL pipelines.
        $this->assertSame(
            $this->pipeline('MACRO Global')->id,
            app(DealService::class)->defaultSalesPipelineId(),
        );
    }

    public function test_archive_is_idempotent_and_reversible(): void
    {
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);
        // Re-seed: archive must stay put, no duplicates, canon stable.
        $this->seed(AmoPipelineSeeder::class);

        $prodazhi = Pipeline::where('name', 'Продажи')->firstOrFail();
        $this->assertFalse($prodazhi->is_active);
        $this->assertSame(11, PipelineStage::where('pipeline_id', $prodazhi->id)->count());

        // Reversible: flip is_active back and the funnel + its stages are intact.
        $prodazhi->update(['is_active' => true]);
        $restored = Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
        $this->assertTrue($restored->is_active);
        $this->assertCount(11, $restored->stages);
    }

    // -------------------------------------------------------------------------
    // StageClassificationService on a new funnel
    // -------------------------------------------------------------------------

    public function test_classification_positions_on_macro_global(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $service = app(StageClassificationService::class);

        $stages = PipelineStage::where('pipeline_id', $this->pipeline('MACRO Global')->id)
            ->get()->keyBy('code');

        // Real (non-won/lost/cold) stages: unsorted, partner, outbound, inbound,
        // qualification, schedule, walking, meeting, warm, trial, hot = 11.
        $this->assertSame(1, $service->funnelPosition($stages['unsorted']));
        $this->assertSame(11, $service->funnelPosition($stages['hot']));

        // Won sits above every real stage (top = realCount + 1 = 12).
        $this->assertSame(12, $service->funnelPosition($stages['success']));

        // cold = -1, lost = -2.
        $this->assertSame(-1, $service->funnelPosition($stages['cold']));
        $this->assertSame(-2, $service->funnelPosition($stages['lost']));

        // Forward / downgrade / flags.
        $this->assertTrue($service->isForwardMove($stages['qualification'], $stages['hot']));
        $this->assertFalse($service->isForwardMove($stages['hot'], $stages['cold']));
        $this->assertTrue($service->isFunnelDowngrade($stages['warm'], $stages['cold']));
        $this->assertTrue($service->isWon($stages['success']));
        $this->assertTrue($service->isLost($stages['lost']));
        $this->assertTrue($service->isCold($stages['cold']));
        $this->assertFalse($service->isCold($stages['lost']));
    }

    public function test_classification_ranks_skip_walking_on_ai_global(): void
    {
        $this->seed(AmoPipelineSeeder::class);

        $service = app(StageClassificationService::class);

        $stages = PipelineStage::where('pipeline_id', $this->pipeline('MACRO AI Global')->id)
            ->get()->keyBy('code');

        // Real stages: unsorted, long_term, outbound, inbound, qualification,
        // schedule, meeting, warm, trial, hot = 10 (no walking).
        $this->assertSame(10, $service->funnelPosition($stages['hot']));
        $this->assertSame(11, $service->funnelPosition($stages['success']));
        $this->assertSame(-1, $service->funnelPosition($stages['cold']));
    }

    // -------------------------------------------------------------------------
    // StageMeta emoji for the new codes
    // -------------------------------------------------------------------------

    public function test_stage_meta_emoji_for_new_codes(): void
    {
        $expected = [
            'unsorted' => '🆕',
            'partner' => '🆕',
            'long_term' => '🆕',
            'outbound' => '🆕',
            'inbound' => '🆕',
            'qualification' => '🟡',
            'schedule' => '🟢',
            'walking' => '🟣',
            'meeting' => '🟣',
            'cold' => '🔵',
            'warm' => '🟠',
            'trial' => '🟠',
            'hot' => '🔴',
            'success' => '⭐',
            'lost' => '☠️',
        ];

        foreach ($expected as $code => $emoji) {
            $this->assertSame($emoji, StageMeta::forCode($code)->emoji, "emoji for $code");
        }
    }

    public function test_stage_meta_sla_weekly_for_amo_codes(): void
    {
        // top_stuck weekly thresholds from the AMO spec (§5.2).
        $this->assertSame(2, StageMeta::forCode('hot')->slaWeekly);
        $this->assertSame(5, StageMeta::forCode('warm')->slaWeekly);
        $this->assertSame(5, StageMeta::forCode('trial')->slaWeekly);
        $this->assertSame(3, StageMeta::forCode('meeting')->slaWeekly);
        $this->assertSame(3, StageMeta::forCode('walking')->slaWeekly);
        $this->assertSame(3, StageMeta::forCode('schedule')->slaWeekly);
        $this->assertSame(7, StageMeta::forCode('qualification')->slaWeekly);
        $this->assertSame(7, StageMeta::forCode('inbound')->slaWeekly);
        $this->assertSame(7, StageMeta::forCode('outbound')->slaWeekly);
        $this->assertSame(30, StageMeta::forCode('cold')->slaWeekly);
    }
}
