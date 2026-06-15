<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\AutomationRunFilter;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\AutomationTargetType;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationRunQueryService;
use App\Domain\Sales\Models\Pipeline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationRunQueryService journal filters (M7 P3).
 *
 * The read-only journal that backs the future GET /api/automation-runs. Locks
 * each filter (automation, target, status, action_kind, period) and the
 * newest-first ordering.
 */
class AutomationRunQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationRunQueryService
    {
        return app(AutomationRunQueryService::class);
    }

    private function automation(ActionKind $action = ActionKind::CreateTask): PipelineAutomation
    {
        $pipeline = Pipeline::factory()->create();

        return PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'action_kind' => $action,
        ]);
    }

    private function makeRun(
        PipelineAutomation $automation,
        RunStatus $status = RunStatus::Success,
        int $targetId = 1,
        AutomationTargetType $type = AutomationTargetType::Deal,
        ?CarbonImmutable $createdAt = null,
    ): AutomationRun {
        return AutomationRun::create([
            'automation_id' => $automation->id,
            'target_type' => $type->value,
            'target_id' => $targetId,
            'status' => $status->value,
            'trigger_event_ts' => null,
            'started_at' => $createdAt ?? now(),
            'finished_at' => $createdAt ?? now(),
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_unfiltered_returns_all_runs_newest_first(): void
    {
        $automation = $this->automation();
        $old = $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDays(2));
        $new = $this->makeRun($automation, createdAt: CarbonImmutable::now());

        $rows = $this->service()->query(new AutomationRunFilter)->get();

        $this->assertCount(2, $rows);
        $this->assertSame($new->id, $rows->first()->id, 'Newest run must come first.');
        $this->assertSame($old->id, $rows->last()->id);
    }

    public function test_filter_by_automation(): void
    {
        $a = $this->automation();
        $b = $this->automation();
        $this->makeRun($a);
        $this->makeRun($a);
        $this->makeRun($b);

        $rows = $this->service()->query(new AutomationRunFilter(automationId: $a->id))->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->automation_id === $a->id));
    }

    public function test_filter_by_status(): void
    {
        $automation = $this->automation();
        $this->makeRun($automation, RunStatus::Success);
        $failed = $this->makeRun($automation, RunStatus::Failed);
        $this->makeRun($automation, RunStatus::Skipped);

        $rows = $this->service()->query(new AutomationRunFilter(status: RunStatus::Failed))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($failed->id, $rows->first()->id);
    }

    public function test_filter_by_target(): void
    {
        $automation = $this->automation();
        $hit = $this->makeRun($automation, targetId: 42);
        $this->makeRun($automation, targetId: 99);

        $rows = $this->service()->query(new AutomationRunFilter(
            targetType: AutomationTargetType::Deal,
            targetId: 42,
        ))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($hit->id, $rows->first()->id);
    }

    public function test_filter_by_action_kind_via_parent_automation(): void
    {
        $webhookAuto = $this->automation(ActionKind::Webhook);
        $taskAuto = $this->automation(ActionKind::CreateTask);
        $hit = $this->makeRun($webhookAuto, RunStatus::Failed);
        $this->makeRun($taskAuto, RunStatus::Failed);

        // "Failed webhooks" — the classic journal slice.
        $rows = $this->service()->query(new AutomationRunFilter(
            status: RunStatus::Failed,
            actionKind: ActionKind::Webhook,
        ))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($hit->id, $rows->first()->id);
    }

    public function test_filter_by_period(): void
    {
        $automation = $this->automation();
        $inside = $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDays(3));
        // Before the window.
        $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDays(20));
        // After the window.
        $this->makeRun($automation, createdAt: CarbonImmutable::now());

        $rows = $this->service()->query(new AutomationRunFilter(
            from: CarbonImmutable::now()->subDays(7),
            to: CarbonImmutable::now()->subDay(),
        ))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inside->id, $rows->first()->id);
    }

    public function test_open_ended_period_lower_bound_only(): void
    {
        $automation = $this->automation();
        $recent = $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDay());
        $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDays(30));

        $rows = $this->service()->query(new AutomationRunFilter(
            from: CarbonImmutable::now()->subDays(7),
        ))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($recent->id, $rows->first()->id);
    }

    public function test_combined_filters_intersect(): void
    {
        $automation = $this->automation(ActionKind::Webhook);
        $hit = $this->makeRun($automation, RunStatus::Failed, targetId: 7, createdAt: CarbonImmutable::now()->subDay());
        // Same automation, wrong status.
        $this->makeRun($automation, RunStatus::Success, targetId: 7);
        // Same automation+status, wrong target.
        $this->makeRun($automation, RunStatus::Failed, targetId: 8);

        $rows = $this->service()->query(new AutomationRunFilter(
            automationId: $automation->id,
            targetType: AutomationTargetType::Deal,
            targetId: 7,
            status: RunStatus::Failed,
            actionKind: ActionKind::Webhook,
            from: CarbonImmutable::now()->subDays(7),
        ))->get();

        $this->assertCount(1, $rows);
        $this->assertSame($hit->id, $rows->first()->id);
    }

    public function test_paginate_clamps_per_page_and_orders(): void
    {
        $automation = $this->automation();
        for ($i = 0; $i < 3; $i++) {
            $this->makeRun($automation, createdAt: CarbonImmutable::now()->subDays($i));
        }

        $page = $this->service()->paginate(new AutomationRunFilter, 2);

        $this->assertSame(2, $page->perPage());
        $this->assertSame(3, $page->total());
        $this->assertCount(2, $page->items());
    }
}
