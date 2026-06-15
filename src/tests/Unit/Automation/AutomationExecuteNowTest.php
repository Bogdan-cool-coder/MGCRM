<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Exceptions\DryRunTargetRequiredException;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationTestService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AutomationTestService::executeNow (M7) — the real manual-run path.
 *
 * Distinct from dryRun(): it claims an idempotency slot and runs the action FOR
 * REAL (set_field mutates synchronously). Asserts the executed/skipped tallies,
 * that runs are persisted, idempotency on re-run, and the inline-needs-target
 * guard. Network actions aren't used (set_field is synchronous), so we still
 * preventStrayRequests to guarantee no accidental outbound IO leaks from the path.
 */
class AutomationExecuteNowTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationTestService
    {
        return app(AutomationTestService::class);
    }

    public function test_pinned_inline_target_executes_real_side_effect(): void
    {
        Http::preventStrayRequests();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Renamed'],
        ]);
        $deal = Deal::factory()->inStage($stage)->create(['title' => 'Before']);

        $result = $this->service()->executeNow($automation, $deal->id);

        $this->assertSame(1, $result->executed);
        $this->assertSame(0, $result->skipped);
        $this->assertCount(1, $result->runs);
        $this->assertSame(RunStatus::Success, $result->runs[0]->status);

        // Real mutation happened (dry-run would NOT have touched the deal).
        $this->assertSame('Renamed', $deal->fresh()->title);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_inline_trigger_without_target_throws(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $this->expectException(DryRunTargetRequiredException::class);

        $this->service()->executeNow($automation);
    }

    public function test_cron_trigger_resolves_up_to_limit(): void
    {
        Http::preventStrayRequests();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Nudged'],
        ]);
        Deal::factory()->count(5)->inStage($stage)->create(['stage_changed_at' => now()->subDays(7)]);

        $result = $this->service()->executeNow($automation, null, 3);

        $this->assertSame(3, $result->executed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(3, AutomationRun::count());
    }

    public function test_rerunning_same_cron_target_is_deduped_as_skipped(): void
    {
        Http::preventStrayRequests();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'idle_in_stage_days',
            'trigger_config' => ['days' => 3],
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'Nudged'],
        ]);
        // Fixed stage_changed_at → identical trigger_event_ts across runs.
        Deal::factory()->inStage($stage)->create(['stage_changed_at' => now()->subDays(7)]);

        $first = $this->service()->executeNow($automation);
        $this->assertSame(1, $first->executed);
        $this->assertSame(0, $first->skipped);

        $second = $this->service()->executeNow($automation);
        $this->assertSame(0, $second->executed, 'Slot already held — no re-execute.');
        $this->assertSame(1, $second->skipped);
        $this->assertCount(0, $second->runs);

        $this->assertSame(1, AutomationRun::count(), 'Idempotency: no duplicate run on re-execute.');
    }

    public function test_pinned_target_from_other_pipeline_matches_nothing(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $automation = PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'trigger_kind' => 'on_enter_stage',
            'action_kind' => 'set_field',
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $other = Pipeline::factory()->create();
        $otherStage = PipelineStage::factory()->create(['pipeline_id' => $other->id]);
        $foreign = Deal::factory()->inStage($otherStage)->create();

        $result = $this->service()->executeNow($automation, $foreign->id);

        $this->assertSame(0, $result->executed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, AutomationRun::count());
    }
}
