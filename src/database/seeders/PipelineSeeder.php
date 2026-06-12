<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the locked AmoCRM-style "Продажи" sales
 * pipeline + its 11 stages. Codes/order/flags are the source of truth (S1.3 plan,
 * section В) — do NOT reorder or rename without an explicit request. Re-running
 * does not create duplicates (updateOrCreate by pipeline+code). parent_stage_id
 * for sub-statuses is resolved in a second pass.
 */
class PipelineSeeder extends Seeder
{
    /**
     * Locked stage list. Order = sort_order (1..11).
     *
     * @var list<array{code: string, name: string, is_won?: bool, is_lost?: bool, hidden?: bool, won_gate?: bool, parent?: string, features?: list<string>, color?: string}>
     */
    private const STAGES = [
        ['code' => 'lost', 'name' => 'Сделка проиграна', 'is_lost' => true, 'hidden' => true, 'color' => '#d32f2f'],
        ['code' => 'new', 'name' => 'Новые лиды', 'color' => '#90a4ae'],
        ['code' => 'qualify', 'name' => 'Квалификация', 'color' => '#42a5f5'],
        ['code' => 'schedule_meeting', 'name' => 'Назначить встречу', 'features' => ['send_presentation'], 'color' => '#26c6da'],
        ['code' => 'meeting', 'name' => 'Встреча', 'features' => ['meeting_report'], 'color' => '#26a69a'],
        ['code' => 'cold', 'name' => 'Холодные (заморозка)', 'hidden' => true, 'color' => '#78909c'],
        ['code' => 'warm', 'name' => 'Тёплые', 'features' => ['generate_document'], 'color' => '#ffa726'],
        ['code' => 'hot', 'name' => 'Горячие', 'features' => ['generate_document'], 'color' => '#ff7043'],
        ['code' => 'won', 'name' => 'Успешная сделка', 'is_won' => true, 'won_gate' => true, 'color' => '#66bb6a'],
        ['code' => 'await_payment', 'name' => 'Ожидаем оплату', 'is_won' => true, 'parent' => 'won', 'color' => '#9ccc65'],
        ['code' => 'paid', 'name' => 'Оплачено', 'is_won' => true, 'parent' => 'won', 'color' => '#43a047'],
    ];

    public function run(): void
    {
        $pipeline = Pipeline::firstOrCreate(
            ['kind' => PipelineKind::Sales->value, 'name' => 'Продажи'],
            ['settings' => [], 'is_active' => true, 'sort_order' => 0],
        );

        // First pass: upsert all stages by (pipeline, code).
        $byCode = [];
        foreach (self::STAGES as $index => $def) {
            $stage = PipelineStage::updateOrCreate(
                ['pipeline_id' => $pipeline->id, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'sort_order' => $index + 1,
                    'color' => $def['color'] ?? null,
                    'is_won' => $def['is_won'] ?? false,
                    'is_lost' => $def['is_lost'] ?? false,
                    'hidden_by_default' => $def['hidden'] ?? false,
                    'won_gate' => $def['won_gate'] ?? false,
                    'stage_features' => $def['features'] ?? [],
                ],
            );
            $byCode[$def['code']] = $stage;
        }

        // Second pass: resolve parent_stage_id for sub-statuses.
        foreach (self::STAGES as $def) {
            if (! isset($def['parent'])) {
                continue;
            }
            $parent = $byCode[$def['parent']] ?? null;
            if ($parent !== null) {
                $byCode[$def['code']]->update(['parent_stage_id' => $parent->id]);
            }
        }
    }
}
