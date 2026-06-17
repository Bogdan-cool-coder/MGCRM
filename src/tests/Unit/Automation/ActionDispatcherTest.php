<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Actions\ActionHandler;
use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\SendAutomationTelegramJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ActionDispatcherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a dispatcher around a single stub handler for the given kind.
     */
    private function dispatcherWith(ActionHandler $handler): ActionDispatcher
    {
        return new ActionDispatcher(new AutomationEngine, [$handler]);
    }

    public function test_dispatch_claims_runs_and_finalizes_success(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create([
            'action_kind' => ActionKind::SetField,
            'action_config' => ['field' => 'title', 'value' => 'x'],
        ]);

        $handler = $this->stubHandler(ActionKind::SetField, ActionResult::success('done', ['k' => 'v']));
        $run = $this->dispatcherWith($handler)->dispatch($automation, $deal, now());

        $this->assertNotNull($run);
        $this->assertSame(RunStatus::Success, $run->status);
        $this->assertSame('done', $run->result['summary']);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($automation->fresh()->last_run_at);
    }

    public function test_dispatch_is_idempotent_for_same_event(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['action_kind' => ActionKind::SetField]);
        $handler = $this->stubHandler(ActionKind::SetField, ActionResult::success('done'));
        $dispatcher = $this->dispatcherWith($handler);
        $ts = now();

        $first = $dispatcher->dispatch($automation, $deal, $ts);
        $second = $dispatcher->dispatch($automation, $deal, $ts);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeated dispatch for the same event must be deduped.');
        $this->assertDatabaseCount('automation_runs', 1);
    }

    public function test_dispatch_failed_releases_slot_for_retry(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['action_kind' => ActionKind::SetField]);

        // Handler throws → dispatcher must catch, mark failed, release the slot.
        $throwing = new class implements ActionHandler
        {
            public function kind(): ActionKind
            {
                return ActionKind::SetField;
            }

            public function execute(PipelineAutomation $a, Deal $t, array $c): ActionResult
            {
                throw new RuntimeException('boom');
            }

            public function dryRun(PipelineAutomation $a, Deal $t, array $c): ActionPreview
            {
                return ActionPreview::will('x');
            }
        };

        $dispatcher = $this->dispatcherWith($throwing);
        $ts = now();
        $run = $dispatcher->dispatch($automation, $deal, $ts);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('boom', $run->error_message);
        $this->assertNull($run->trigger_event_ts, 'failed must release the idempotency slot.');

        // Slot freed: a successful handler can re-claim the same event.
        $ok = $this->stubHandler(ActionKind::SetField, ActionResult::success('ok'));
        $retry = $this->dispatcherWith($ok)->dispatch($automation->fresh(), $deal, $ts);
        $this->assertNotNull($retry);
    }

    public function test_dispatch_queued_result_dispatches_job(): void
    {
        Queue::fake();
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['action_kind' => ActionKind::TgNotify]);

        $handler = $this->stubHandler(
            ActionKind::TgNotify,
            ActionResult::queued('queued', ['chat_id' => '1'], fn (int $runId): SendAutomationTelegramJob => new SendAutomationTelegramJob($runId, '1', 'hi')),
        );

        $run = $this->dispatcherWith($handler)->dispatch($automation, $deal, now());

        $this->assertSame(RunStatus::Queued, $run->status);
        Queue::assertPushed(SendAutomationTelegramJob::class);
    }

    public function test_dispatch_skips_unknown_action_kind(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['action_kind' => ActionKind::Email]);

        // Dispatcher built with NO handlers — unknown kind → skipped run.
        $run = (new ActionDispatcher(new AutomationEngine, []))->dispatch($automation, $deal, now());

        $this->assertSame(RunStatus::Skipped, $run->status);
    }

    public function test_dry_run_delegates_to_handler_without_run(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['action_kind' => ActionKind::SetField]);
        $handler = $this->stubHandler(ActionKind::SetField, ActionResult::success('x'), ActionPreview::will('preview'));

        $preview = $this->dispatcherWith($handler)->dryRun($automation, $deal);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('preview', $preview->summary);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_full_registry_resolves_all_eight_handlers(): void
    {
        // The container-bound dispatcher must carry every MVP action.
        $dispatcher = app(ActionDispatcher::class);
        $deal = Deal::factory()->create();

        foreach (ActionKind::cases() as $kind) {
            $automation = PipelineAutomation::factory()->create([
                'action_kind' => $kind,
                'action_config' => [],
            ]);
            // dryRun must produce a preview (a real handler, not the "no handler" wont).
            $preview = $dispatcher->dryRun($automation, $deal);
            $this->assertStringNotContainsString('No handler', $preview->summary, "missing handler for {$kind->value}");
        }
    }

    private function stubHandler(ActionKind $kind, ActionResult $result, ?ActionPreview $preview = null): ActionHandler
    {
        return new class($kind, $result, $preview ?? ActionPreview::will('p')) implements ActionHandler
        {
            public function __construct(
                private readonly ActionKind $kind,
                private readonly ActionResult $result,
                private readonly ActionPreview $preview,
            ) {}

            public function kind(): ActionKind
            {
                return $this->kind;
            }

            public function execute(PipelineAutomation $a, Deal $t, array $c): ActionResult
            {
                return $this->result;
            }

            public function dryRun(PipelineAutomation $a, Deal $t, array $c): ActionPreview
            {
                return $this->preview;
            }
        };
    }
}
