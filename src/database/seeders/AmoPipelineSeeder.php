<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the TWO real AmoCRM funnels mirrored into
 * MGCRM 1-for-1 (SalesPulse milestone): "MACRO Global" and "MACRO AI Global".
 *
 * These exist so the SalesPulse oversight bot tracks the SAME funnels the team
 * uses in AMO and for test runs. Stage names / order / colours / flags are the
 * source of truth (pulled verbatim from the AMO API) — do NOT change without an
 * explicit request. This seeder is INDEPENDENT of the locked "Продажи" funnel
 * (PipelineSeeder); it never touches it.
 *
 * Idempotency: pipeline via firstOrCreate(kind+name), stages via updateOrCreate
 * (pipeline+code). Re-running does not duplicate. sort_order is sequential 1..N;
 * the won/lost system stages carry the two highest values so they read at the
 * bottom of the funnel both raw and via Pipeline::stages (which also system-ranks
 * is_won/is_lost to the bottom).
 *
 * The `cold` code is registered in config('salespulse.cold_stage_codes') so
 * StageClassificationService freeze-detects it; every stage code below has a row
 * in config('salespulse.stages') (emoji + SLA windows).
 *
 * CANON (SalesPulse cutover): the AMO funnels are the PRIMARY sales pipelines.
 * "MACRO Global" carries sort_order 0 → it is the DEFAULT board view and the
 * default pipeline for deal creation (default = first ACTIVE sales pipeline by
 * sort_order, see DealService::defaultSalesPipelineId / PipelineService::
 * defaultSalesPipeline). "MACRO AI Global" is sort_order 1. The locked "Продажи"
 * funnel is ARCHIVED here — REVERSIBLY: is_active=false + sort_order pushed to the
 * end. Its 11 stages and rows are NEVER touched, so flipping is_active back to
 * true fully restores it. This seeder runs AFTER PipelineSeeder in the baseline
 * list, so re-seeding / migrate:fresh / reset-clean all converge on this canon.
 */
class AmoPipelineSeeder extends Seeder
{
    /**
     * "MACRO Global" funnel — 14 stages in AMO order.
     *
     * @var list<array{code: string, name: string, color: string, is_won?: bool, is_lost?: bool, hidden?: bool}>
     */
    private const MACRO_GLOBAL_STAGES = [
        ['code' => 'unsorted', 'name' => 'Неразобранное', 'color' => '#c1c1c1'],
        ['code' => 'partner', 'name' => 'партнерский канал', 'color' => '#87f2c0'],
        ['code' => 'outbound', 'name' => '1. Outbound leads', 'color' => '#99ccff'],
        ['code' => 'inbound', 'name' => '1. INBOUND Leads', 'color' => '#98cbff'],
        ['code' => 'qualification', 'name' => '2. qualification', 'color' => '#fff000'],
        ['code' => 'schedule', 'name' => '3. schedule a meeting', 'color' => '#87f2c0'],
        ['code' => 'walking', 'name' => '4.1. walking', 'color' => '#f9deff'],
        ['code' => 'meeting', 'name' => '4.2. Meeting', 'color' => '#eb93ff'],
        ['code' => 'cold', 'name' => '5. cold deals', 'color' => '#fff000', 'hidden' => true],
        ['code' => 'warm', 'name' => '6. warm deals', 'color' => '#ffeab2'],
        ['code' => 'trial', 'name' => '6.1. Trial', 'color' => '#ffce5a'],
        ['code' => 'hot', 'name' => '7. HOT deals', 'color' => '#ff8f92'],
        ['code' => 'success', 'name' => '8. success', 'color' => '#CCFF66', 'is_won' => true],
        ['code' => 'lost', 'name' => 'lost', 'color' => '#D5D8DB', 'is_lost' => true],
    ];

    /**
     * "MACRO AI Global" funnel — 13 stages. Differs from MACRO Global:
     *   - position 2 is "В долгосрочной перспективе" (long_term) instead of partner
     *   - NO "4.1. walking" stage
     *   - qualification / cold / warm carry different colours
     *
     * @var list<array{code: string, name: string, color: string, is_won?: bool, is_lost?: bool, hidden?: bool}>
     */
    private const MACRO_AI_GLOBAL_STAGES = [
        ['code' => 'unsorted', 'name' => 'Неразобранное', 'color' => '#c1c1c1'],
        ['code' => 'long_term', 'name' => 'В долгосрочной перспективе', 'color' => '#ccc8f9'],
        ['code' => 'outbound', 'name' => '1. Outbound leads', 'color' => '#99ccff'],
        ['code' => 'inbound', 'name' => '1. INBOUND Leads', 'color' => '#98cbff'],
        ['code' => 'qualification', 'name' => '2. qualification', 'color' => '#ffff99'],
        ['code' => 'schedule', 'name' => '3. schedule a meeting', 'color' => '#87f2c0'],
        ['code' => 'meeting', 'name' => '4.2. Meeting', 'color' => '#eb93ff'],
        ['code' => 'cold', 'name' => '5. cold deals', 'color' => '#fffeb2', 'hidden' => true],
        ['code' => 'warm', 'name' => '6. warm deals', 'color' => '#ffdc7f'],
        ['code' => 'trial', 'name' => '6.1. Trial', 'color' => '#ffce5a'],
        ['code' => 'hot', 'name' => '7. HOT deals', 'color' => '#ff8f92'],
        ['code' => 'success', 'name' => '8. success', 'color' => '#CCFF66', 'is_won' => true],
        ['code' => 'lost', 'name' => 'lost', 'color' => '#D5D8DB', 'is_lost' => true],
    ];

    public function run(): void
    {
        // MACRO Global at sort_order 0 → the default sales pipeline (board view +
        // deal-create default). MACRO AI Global is the active second funnel.
        $this->seedFunnel('MACRO Global', self::MACRO_GLOBAL_STAGES, sortOrder: 0);
        $this->seedFunnel('MACRO AI Global', self::MACRO_AI_GLOBAL_STAGES, sortOrder: 1);

        // Reversibly archive the locked "Продажи" funnel so the two AMO funnels are
        // the only ACTIVE sales pipelines. We touch ONLY is_active + sort_order —
        // never its stages — so restoring is a single is_active flip.
        $this->archiveLegacyProdazhiFunnel();
    }

    /**
     * Push the locked "Продажи" funnel out of the active set: is_active=false and
     * sort_order to the tail (after both AMO funnels). Idempotent and reversible —
     * stages are untouched. No-op when the funnel was never seeded (e.g. a test
     * that only seeds the AMO funnels).
     */
    private function archiveLegacyProdazhiFunnel(): void
    {
        Pipeline::query()
            ->where('kind', PipelineKind::Sales->value)
            ->where('name', 'Продажи')
            ->update(['is_active' => false, 'sort_order' => 2]);
    }

    /**
     * @param  list<array{code: string, name: string, color: string, is_won?: bool, is_lost?: bool, hidden?: bool}>  $stages
     */
    private function seedFunnel(string $name, array $stages, int $sortOrder): void
    {
        $pipeline = Pipeline::firstOrCreate(
            ['kind' => PipelineKind::Sales->value, 'name' => $name],
            ['settings' => [], 'is_active' => true, 'sort_order' => $sortOrder],
        );

        // Idempotently re-assert the canon (active + correct sort_order) on re-seed
        // so an older sort_order from a prior version converges on migrate:fresh /
        // reset-clean without ever duplicating the pipeline or its stages.
        if ($pipeline->is_active !== true || $pipeline->sort_order !== $sortOrder) {
            $pipeline->update(['is_active' => true, 'sort_order' => $sortOrder]);
        }

        foreach ($stages as $index => $def) {
            PipelineStage::updateOrCreate(
                ['pipeline_id' => $pipeline->id, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'sort_order' => $index + 1,
                    'color' => $def['color'],
                    'is_won' => $def['is_won'] ?? false,
                    'is_lost' => $def['is_lost'] ?? false,
                    'hidden_by_default' => $def['hidden'] ?? false,
                    'won_gate' => false,
                    'stage_features' => [],
                ],
            );
        }
    }
}
