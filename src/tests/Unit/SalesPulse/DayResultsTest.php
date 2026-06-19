<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Services\DayResultsService;
use App\Domain\SalesPulse\Services\StageClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DayResultsService (spec §5.1). Covers: (a) payload building (closed_today_tasks
 * / plan_pending_tasks shapes + hint classification), (b) the wrapped LLM output
 * "<b>{name}</b>\n\n{body}", (c) the deterministic offline fallback when the LLM
 * is unavailable. The LLM is a FakePulseLlmClient — no network.
 */
class DayResultsTest extends TestCase
{
    use RefreshDatabase;
    use SalesPulseTestSupport;

    private FakePulseLlmClient $llm;

    private DayResultsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFunnel();
        $this->llm = new FakePulseLlmClient;
        $this->service = new DayResultsService($this->llm, app(StageClassificationService::class));
    }

    public function test_payload_shapes_and_forward_hint(): void
    {
        $manager = $this->makeManager();
        $meeting = $this->stage('meeting');
        $hot = $this->stage('hot');

        // Deal moved meeting → hot, the closing task is a forward (transitional + jump?).
        $deal = $this->makeDeal('hot', $manager);

        // Morning plan: deal in meeting; evening: deal in hot, task done.
        $planRow = $this->row(1, (int) $deal->id, $meeting->id);
        $plan = $this->snapshot(plan: [$planRow], managerId: (int) $manager->id);

        $evRow = $this->row(1, (int) $deal->id, $hot->id, completed: true);
        $evRow->resultText = 'ВД провёл встречу СД договор';
        $evening = $this->snapshot(plan: [$evRow], fact: [$evRow], managerId: (int) $manager->id);

        $payload = $this->service->buildPayload((string) $manager->full_name, $plan, $evening, []);

        $this->assertSame((string) $manager->full_name, $payload['manager']);
        $this->assertCount(1, $payload['closed_today_tasks']);
        $this->assertCount(0, $payload['plan_pending_tasks']); // the only plan task got done.

        $closed = $payload['closed_today_tasks'][0];
        $this->assertSame('🟣', $closed['status_before_emoji']); // meeting
        $this->assertSame('🔴', $closed['status_after_emoji']);  // hot
        $this->assertTrue($closed['stage_changed']);
        $this->assertTrue($closed['is_transitional']);
        $this->assertTrue($closed['from_morning_plan']);
        $this->assertStringContainsString('WIN: переходная задача', $closed['hint']);
    }

    public function test_lost_and_cold_hints(): void
    {
        $service = $this->service;
        $qualify = $this->stage('qualify');
        $lost = $this->stage('lost');
        $cold = $this->stage('cold');

        $lostHint = $service->classifyHint($qualify, $lost, false, 'Звонок');
        $this->assertSame('FLAG: ушла в lost', $lostHint);

        $coldHint = $service->classifyHint($qualify, $cold, false, 'Звонок');
        $this->assertSame('FLAG: откат в cold', $coldHint);
    }

    public function test_success_and_jump_hints(): void
    {
        $service = $this->service;
        $meeting = $this->stage('meeting');
        $won = $this->stage('won');

        $hint = $service->classifyHint($meeting, $won, true, 'Договор');
        $this->assertStringContainsString('WIN: success (оплата/договор)', $hint);
        // meeting → won skips stages → 🚀 jump.
        $this->assertStringContainsString('WIN: 🚀 скачок через этап', $hint);
    }

    public function test_pending_task_payload_carries_history_and_notes(): void
    {
        $manager = $this->makeManager();
        $warm = $this->stage('warm');
        $deal = $this->makeDeal('warm', $manager);

        $planRow = $this->row(7, (int) $deal->id, $warm->id);
        $planRow->carryoverDays = 3;
        $planRow->daysInStage = 4;
        $plan = $this->snapshot(plan: [$planRow], managerId: (int) $manager->id);

        // Evening: task 7 still open.
        $evRow = $this->row(7, (int) $deal->id, $warm->id);
        $evening = $this->snapshot(plan: [$evRow], managerId: (int) $manager->id);

        $payload = $this->service->buildPayload((string) $manager->full_name, $plan, $evening, [(int) $deal->id => true]);

        $this->assertCount(0, $payload['closed_today_tasks']);
        $this->assertCount(1, $payload['plan_pending_tasks']);

        $pending = $payload['plan_pending_tasks'][0];
        $this->assertSame(3, $pending['carryover_days']);
        $this->assertSame(4, $pending['days_in_stage']);
        $this->assertTrue($pending['notes_today']);
        $this->assertSame('🟠', $pending['status_now_emoji']);
    }

    public function test_llm_output_is_wrapped_with_manager_name(): void
    {
        $manager = $this->makeManager();
        $this->llm->textReply = "🏆 Ключевые достижения\n• всё ок\n\n🚩 Красные флаги\n—";

        $evening = $this->snapshot(plan: [], managerId: (int) $manager->id);
        $out = $this->service->renderForManager((string) $manager->full_name, null, $evening, []);

        $this->assertStringStartsWith('<b>'.$manager->full_name.'</b>'."\n\n", $out);
        $this->assertStringContainsString('🏆 Ключевые достижения', $out);
        // The system prompt is the verbatim §5.1 contract.
        $this->assertStringContainsString('Ты аналитик отдела продаж MACRO Global', (string) $this->llm->lastSystemPrompt);
    }

    public function test_offline_fallback_buckets_and_headers(): void
    {
        $manager = $this->makeManager();
        $this->llm->available = false;

        $won = $this->stage('won');
        $lost = $this->stage('lost');

        $wonDeal = $this->makeDeal('won', $manager);
        $lostDeal = $this->makeDeal('lost', $manager);

        $wonRow = $this->row(1, (int) $wonDeal->id, $won->id, completed: true);
        $wonRow->resultText = 'оплата получена';
        $lostRow = $this->row(2, (int) $lostDeal->id, $lost->id, completed: true);
        $lostRow->resultText = 'клиент ушёл';

        $evening = $this->snapshot(plan: [$wonRow, $lostRow], fact: [$wonRow, $lostRow], managerId: (int) $manager->id);

        $out = $this->service->renderForManager((string) $manager->full_name, null, $evening, [], missed: 2);

        $this->assertStringStartsWith('<b>'.$manager->full_name.'</b>', $out);
        $this->assertStringContainsString('🏆 Ключевые достижения', $out);
        $this->assertStringContainsString('🚩 Красные флаги', $out);
        // won → achievement (⭐), lost → flag (☠️), + missed line.
        $this->assertStringContainsString('⭐ (deal '.$wonDeal->id.')', $out);
        $this->assertStringContainsString('☠️ (deal '.$lostDeal->id.')', $out);
        $this->assertStringContainsString('+ Пропущено 2 задач без заметок', $out);
        // The LLM was never called.
        $this->assertNull($this->llm->lastPayload);
    }

    public function test_offline_fallback_when_llm_throws(): void
    {
        $manager = $this->makeManager();
        $this->llm->throwOnCall = true;

        $deal = $this->makeDeal('warm', $manager);
        $row = $this->row(1, (int) $deal->id, $this->stage('warm')->id, completed: true);
        $row->resultText = 'договорились';
        $evening = $this->snapshot(plan: [$row], fact: [$row], managerId: (int) $manager->id);

        $out = $this->service->renderForManager((string) $manager->full_name, null, $evening, []);

        $this->assertStringContainsString('🏆 Ключевые достижения', $out);
        $this->assertStringContainsString('🟠 (deal '.$deal->id.')', $out);
    }
}
