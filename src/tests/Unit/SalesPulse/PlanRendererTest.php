<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Data\PulseStageResolver;
use App\Domain\SalesPulse\Renderers\PlanRenderer;
use App\Domain\SalesPulse\Services\StageClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanRenderer — the /startday plain-text plan (spec §7). Verifies the header, the
 * row format ({emoji} {i}. {company} — {stage}{ ♻️ N-й день} — {✓ }{text}), the
 * hot→cold sort, the carryover suffix, the completed-row checkmark and the footer.
 */
class PlanRendererTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private PlanRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->renderer = new PlanRenderer(app(StageClassificationService::class));
    }

    public function test_empty_plan_renders_no_tasks_line(): void
    {
        $snap = $this->snapshot(plan: [], managerId: 7, onDate: '2026-06-19');

        $this->assertSame('Задач на сегодня нет.', $this->renderer->render($snap, new PulseStageResolver));
    }

    public function test_rows_are_sorted_hot_to_cold_with_emoji_and_footer(): void
    {
        $hot = $this->stage('hot');
        $qualify = $this->stage('qualify');

        // Build a plan with a qualify (cooler) row first and a hot (hotter) row
        // second to prove the renderer re-sorts hot→cold.
        $rowQualify = $this->row(taskId: 10, dealId: 100, stageId: $qualify->id);
        $rowHot = $this->row(taskId: 20, dealId: 200, stageId: $hot->id);

        $snap = $this->snapshot(plan: [$rowQualify, $rowHot], managerId: 1, onDate: '2026-06-19');
        $resolver = PulseStageResolver::fromStages([$hot, $qualify]);

        $out = $this->renderer->render($snap, $resolver);
        $lines = explode("\n", $out);

        $this->assertSame('📋 План на 2026-06-19 — Manager', $lines[0]);
        // Hot first (🔴), qualify second (🟡).
        $this->assertStringStartsWith('🔴 1. deal 200 — ', $lines[1]);
        $this->assertStringStartsWith('🟡 2. deal 100 — ', $lines[2]);
        $this->assertSame('Всего задач: 2', $lines[3]);
    }

    public function test_carryover_suffix_and_completed_checkmark(): void
    {
        $warm = $this->stage('warm');

        // carryover_days = 2 → today is the 3rd day → "♻️ 3-й день".
        $row = $this->row(taskId: 5, dealId: 50, stageId: $warm->id, completed: true);
        $row->carryoverDays = 2;

        $snap = $this->snapshot(plan: [$row], managerId: 1, onDate: '2026-06-19');
        $resolver = PulseStageResolver::fromStages([$warm]);

        $out = $this->renderer->render($snap, $resolver);
        $lines = explode("\n", $out);

        // 🟠 1. deal 50 — {warm name} ♻️ 3-й день — ✓ task 5
        $this->assertStringContainsString('♻️ 3-й день', $lines[1]);
        $this->assertStringContainsString('— ✓ task 5', $lines[1]);
        $this->assertStringStartsWith('🟠 1. deal 50 — ', $lines[1]);
    }
}
