<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\ChangeStageAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Services\EngagementService;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class ChangeStageActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ChangeStageAction
    {
        // DealMoveService needs DocumentService only for the won-gate; the moves
        // under test are not won, so it is never asked.
        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldReceive('hasActiveContractForDeal')->andReturn(true)->byDefault();

        return new ChangeStageAction(new DealMoveService($documents, new EngagementService));
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::ChangeStage, $this->action()->kind());
    }

    public function test_execute_moves_deal_via_deal_move_service(): void
    {
        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);
        $owner = User::factory()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $from->id,
            'owner_user_id' => $owner->id,
        ]);
        $automation = PipelineAutomation::factory()->create(['created_by_user_id' => $owner->id]);

        $result = $this->action()->execute($automation, $deal, ['to_stage_id' => $to->id]);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertSame($to->id, (int) $deal->fresh()->stage_id);
        $this->assertDatabaseHas('deal_stage_history', ['deal_id' => $deal->id, 'to_stage_id' => $to->id]);
    }

    public function test_execute_skips_when_already_in_target_stage_no_loop(): void
    {
        // Loop guard: target == current stage → skip BEFORE move(), so an
        // on_enter_stage→change_stage automation cannot recurse on itself.
        Event::fake();
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action()->execute($automation, $deal, ['to_stage_id' => $stage->id]);

        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertDatabaseCount('deal_stage_history', 0);
        // No stage-change event => no re-entrant on_enter_stage firing.
        Event::assertNotDispatched(DealStageChanged::class);
    }

    public function test_execute_skips_stage_from_other_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);

        $otherStage = PipelineStage::factory()->create(); // different pipeline
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action()->execute($automation, $deal, ['to_stage_id' => $otherStage->id]);

        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertSame($stage->id, (int) $deal->fresh()->stage_id);
    }

    public function test_execute_skips_without_to_stage_id(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action()->execute($automation, $deal, []);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_dry_run_previews_move(): void
    {
        $pipeline = Pipeline::factory()->create();
        $from = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);
        $to = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 2]);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $from->id]);
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action()->dryRun($automation, $deal, ['to_stage_id' => $to->id]);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame($to->id, $preview->data['change_stage']['to_stage_id']);
        $this->assertDatabaseCount('deal_stage_history', 0);
    }
}
