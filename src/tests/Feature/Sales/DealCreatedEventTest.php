<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Jobs\ExecuteAutomationActionJob;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Э3 — DealCreated must be dispatched from BOTH deal-creation paths
 * (createInbound AND the manual create()), and always AFTER the transaction
 * commits so the queued on_create automation listener never observes an
 * uncommitted deal (queue.after_commit=false; a pre-commit dispatch could
 * otherwise leave the automation run stuck `pending` with its idempotency slot
 * consumed and the action lost).
 */
class DealCreatedEventTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    // -------------------------------------------------------------------------
    // Finding 2: the MANUAL create() path also emits DealCreated (on_create was
    // previously inbound-only).
    // -------------------------------------------------------------------------

    public function test_manual_create_emits_deal_created_event(): void
    {
        Event::fake([DealCreated::class]);

        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create();
        $creator = User::factory()->create(['role' => Role::Manager]);

        $deal = app(DealService::class)->create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'Manual deal',
            'currency' => 'RUB',
        ], $creator);

        Event::assertDispatched(
            DealCreated::class,
            fn (DealCreated $e): bool => $e->deal->id === $deal->id,
        );
    }

    public function test_manual_create_fires_on_create_automation_exactly_once(): void
    {
        Queue::fake();

        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create();
        $creator = User::factory()->create(['role' => Role::Manager]);

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
        ]);

        $deal = app(DealService::class)->create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'Manual deal with automation',
            'currency' => 'RUB',
        ], $creator);

        // Exactly one pending run claimed, exactly one execution job queued —
        // the manual path must not double-fire (there is no second inbound
        // dispatch for this deal).
        $this->assertSame(
            1,
            AutomationRun::query()->where('target_id', $deal->id)->count(),
            'Manual create must claim exactly one on_create run.',
        );
        $this->assertDatabaseHas('automation_runs', [
            'target_id' => $deal->id,
            'status' => RunStatus::Pending->value,
        ]);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);
    }

    // -------------------------------------------------------------------------
    // Finding 1: createInbound's on_create run does NOT get stranded `pending`
    // when the execution job is processed after the transaction commits.
    // -------------------------------------------------------------------------

    public function test_inbound_on_create_run_resolves_when_job_runs_after_commit(): void
    {
        // Real queue-less sync is avoided: fake the queue, capture the job, then
        // run it explicitly to simulate a worker picking it up AFTER commit. The
        // deal row is committed by the time createInbound returns, so the run must
        // resolve to a terminal state (never stay pending).
        Queue::fake();

        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        PipelineAutomation::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => null,
            'trigger_kind' => 'on_create',
        ]);

        $deal = app(DealService::class)->createInbound(
            $company,
            ['title' => 'Inbound lead'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        // The deal is committed and a single pending run + job exist.
        $this->assertDatabaseHas('deals', ['id' => $deal->id]);
        $run = AutomationRun::query()->where('target_id', $deal->id)->firstOrFail();
        $this->assertSame(RunStatus::Pending->value, $run->status->value);
        Queue::assertPushed(ExecuteAutomationActionJob::class, 1);

        // Simulate the worker running the job now that the deal is committed. The
        // action handler resolves the run to a terminal status — it never stays
        // stranded in `pending` (which would hold the idempotency slot forever).
        (new ExecuteAutomationActionJob($run->id))->handle(app(ActionDispatcher::class));

        $run->refresh();
        $this->assertNotSame(
            RunStatus::Pending->value,
            $run->status->value,
            'A run processed after commit must resolve, never stay pending.',
        );
    }

    public function test_inbound_deal_is_committed_before_event_dispatch(): void
    {
        // The listener sees a persisted deal: assert the deal row exists at the
        // moment DealCreated is handled (the post-commit contract).
        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        $seenPersisted = false;
        Event::listen(DealCreated::class, function (DealCreated $e) use (&$seenPersisted): void {
            $seenPersisted = Deal::query()->whereKey($e->deal->id)->exists();
        });

        app(DealService::class)->createInbound(
            $company,
            ['title' => 'Committed lead'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        $this->assertTrue($seenPersisted, 'DealCreated must fire only after the deal is committed.');
    }
}
