<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Data\PulseMetrics;
use App\Domain\SalesPulse\Renderers\FactRenderer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * FactRenderer — the /finishday HTML fact (spec §7). Verifies the header, the four
 * sections (done-by-plan / not-done-with-note / not-done-bare / extra), the empty
 * section "—", the no-plan warning, and the trailing metrics block.
 *
 * No DB needed — the renderer classifies rows purely from the snapshots + notes.
 */
class FactRendererTest extends TestCase
{
    use SalesPulseTestSupport;

    private FactRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new FactRenderer;
    }

    private function metrics(): PulseMetrics
    {
        return new PulseMetrics(
            activityDone: 1,
            activityTotal: 3,
            statusUpdates: 0,
            companies: 3,
            missed: 1,
            extraTasks: 1,
            statusDowngrades: 0,
            losts: 0,
        );
    }

    public function test_four_sections_with_classification(): void
    {
        // Morning plan: 3 tasks on 3 deals.
        $planDone = $this->row(taskId: 1, dealId: 11, stageId: null);     // completed in evening
        $planNote = $this->row(taskId: 2, dealId: 22, stageId: null);     // open + deal has note
        $planBare = $this->row(taskId: 3, dealId: 33, stageId: null);     // open + no note
        $plan = $this->snapshot(plan: [$planDone, $planNote, $planBare], managerId: 1, onDate: '2026-06-19');

        // Evening: task 1 completed, task 2/3 still open, plus extra task 9 done.
        $evDone = $this->row(taskId: 1, dealId: 11, stageId: null, completed: true);
        $evNote = $this->row(taskId: 2, dealId: 22, stageId: null);
        $evBare = $this->row(taskId: 3, dealId: 33, stageId: null);
        $evExtra = $this->row(taskId: 9, dealId: 99, stageId: null, completed: true);
        $evening = $this->snapshot(plan: [$evDone, $evNote, $evBare, $evExtra], managerId: 1, onDate: '2026-06-19');

        $notes = [22 => true]; // deal 22 has a note today.

        $out = $this->renderer->render($plan, $evening, $notes, $this->metrics(), CarbonImmutable::parse('2026-06-19'));

        $this->assertStringContainsString('📈 Факт за 19.06 — Manager', $out);
        $this->assertStringContainsString('✅ Выполнено по плану (1)', $out);
        $this->assertStringContainsString('• deal 11 — task 1', $out);
        $this->assertStringContainsString('❗ Не выполнено, но есть заметка (1)', $out);
        $this->assertStringContainsString('• deal 22 — task 2', $out);
        $this->assertStringContainsString('❌ Не выполнено без заметок (1)', $out);
        $this->assertStringContainsString('• deal 33 — task 3', $out);
        $this->assertStringContainsString('🆕 Внеплановые (1)', $out);
        $this->assertStringContainsString('• deal 99 — task 9', $out);
        // Metrics block is appended.
        $this->assertStringContainsString('📊 Показатели:', $out);
    }

    public function test_empty_sections_render_dash(): void
    {
        // Plan with a single open + no-note task → only the bare section is filled.
        $plan = $this->snapshot(plan: [$this->row(1, 5, null)], managerId: 1, onDate: '2026-06-19');
        $evening = $this->snapshot(plan: [$this->row(1, 5, null)], managerId: 1, onDate: '2026-06-19');

        $out = $this->renderer->render($plan, $evening, [], $this->metrics(), CarbonImmutable::parse('2026-06-19'));

        // Done-by-plan, with-note and extra sections are empty → "—".
        $this->assertStringContainsString("✅ Выполнено по плану (0)\n—", $out);
        $this->assertStringContainsString("❗ Не выполнено, но есть заметка (0)\n—", $out);
        $this->assertStringContainsString("🆕 Внеплановые (0)\n—", $out);
    }

    public function test_no_morning_plan_warning_and_all_extra(): void
    {
        $evExtra = $this->row(taskId: 9, dealId: 99, stageId: null, completed: true);
        $evening = $this->snapshot(plan: [$evExtra], managerId: 1, onDate: '2026-06-19');

        $out = $this->renderer->render(null, $evening, [], $this->metrics(), CarbonImmutable::parse('2026-06-19'));

        $this->assertStringContainsString('⚠️ Утреннего плана не было', $out);
        // Every completed evening task is extra when there is no plan.
        $this->assertStringContainsString('🆕 Внеплановые (1)', $out);
        $this->assertStringContainsString('• deal 99 — task 9', $out);
    }
}
