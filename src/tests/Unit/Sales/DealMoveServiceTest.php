<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Exceptions\WonGateException;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DealMoveServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Deal, 1: PipelineStage, 2: User}
     */
    private function makeDeal(?callable $stageState = null, ?callable $targetState = null): array
    {
        $pipeline = Pipeline::factory()->create();
        $open = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'open', 'sort_order' => 1]);
        $targetState ??= fn (array $s): array => $s;
        $target = PipelineStage::factory()->create($targetState([
            'pipeline_id' => $pipeline->id,
            'code' => 'target',
            'sort_order' => 2,
        ]));

        $user = User::factory()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $open->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => $user->id,
        ]);

        return [$deal, $target, $user];
    }

    /**
     * Bind a DocumentService mock into the container and resolve a fresh
     * DealMoveService with it. $hasContract controls hasActiveContractForDeal;
     * pass null to assert the method is NEVER called.
     */
    private function serviceWithContract(?bool $hasContract): DealMoveService
    {
        $documents = Mockery::mock(DocumentService::class);

        if ($hasContract === null) {
            $documents->shouldNotReceive('hasActiveContractForDeal');
        } else {
            $documents->shouldReceive('hasActiveContractForDeal')->andReturn($hasContract);
        }

        return new DealMoveService($documents);
    }

    public function test_redundant_move_is_noop(): void
    {
        [$deal, , $user] = $this->makeDeal();
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, (int) $deal->stage_id, $user->id);

        $this->assertInstanceOf(Deal::class, $result);
        $this->assertDatabaseCount('deal_stage_history', 0);
    }

    public function test_lost_gate_blocks_without_reason(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_lost' => true]);
        $service = $this->serviceWithContract(null);

        $this->expectException(ValidationException::class);
        $service->move($deal, $target->id, $user->id);
    }

    public function test_lost_move_with_reason_sets_closed_at_and_history(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_lost' => true]);
        $reason = LostReason::factory()->create();
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $target->id, $user->id, null, $reason->id);

        $this->assertNotNull($result->closed_at);
        $this->assertSame($reason->id, (int) $result->lost_reason_id);
        $this->assertDatabaseCount('deal_stage_history', 1);
    }

    public function test_won_gate_blocks_without_live_contract(): void
    {
        [$deal, $target, $user] = $this->makeDeal(
            targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => true, 'won_gate_contract_required' => true],
        );
        $service = $this->serviceWithContract(false);

        $caught = false;
        try {
            $service->move($deal, $target->id, $user->id);
        } catch (WonGateException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected a WonGateException.');

        // The transaction rolled back: deal stayed in the original stage, no history.
        $deal->refresh();
        $this->assertNotSame($target->id, (int) $deal->stage_id);
        $this->assertDatabaseCount('deal_stage_history', 0);
    }

    public function test_won_gate_passes_with_live_contract(): void
    {
        [$deal, $target, $user] = $this->makeDeal(
            targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => true, 'won_gate_contract_required' => true],
        );
        $service = $this->serviceWithContract(true);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertSame($target->id, (int) $result->stage_id);
        $this->assertNotNull($result->closed_at);
        $this->assertDatabaseCount('deal_stage_history', 1);
    }

    public function test_won_gate_skipped_when_contract_flag_off(): void
    {
        [$deal, $target, $user] = $this->makeDeal(
            targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => true, 'won_gate_contract_required' => false],
        );
        // hasActiveContractForDeal must NOT be called when the flag is off.
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertSame($target->id, (int) $result->stage_id);
        $this->assertNotNull($result->closed_at);
    }

    public function test_won_gate_skipped_when_won_gate_off(): void
    {
        [$deal, $target, $user] = $this->makeDeal(
            targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => false, 'won_gate_contract_required' => true],
        );
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertSame($target->id, (int) $result->stage_id);
    }

    public function test_non_won_stage_never_gated(): void
    {
        // won_gate=true on a NON-won stage must not trigger the contract gate.
        [$deal, $target, $user] = $this->makeDeal(
            targetState: fn (array $s): array => $s + ['is_won' => false, 'won_gate' => true, 'won_gate_contract_required' => true],
        );
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertSame($target->id, (int) $result->stage_id);
    }

    public function test_move_to_foreign_pipeline_stage_rejected(): void
    {
        [$deal, , $user] = $this->makeDeal();
        $foreignPipeline = Pipeline::factory()->create();
        $foreignStage = PipelineStage::factory()->create(['pipeline_id' => $foreignPipeline->id]);
        $service = $this->serviceWithContract(null);

        $this->expectException(ValidationException::class);
        $service->move($deal, $foreignStage->id, $user->id);
    }

    public function test_real_move_dispatches_deal_stage_changed(): void
    {
        Event::fake([DealStageChanged::class]);

        [$deal, $target, $user] = $this->makeDeal();
        $fromStageId = (int) $deal->stage_id;
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $target->id, $user->id);

        Event::assertDispatched(
            DealStageChanged::class,
            function (DealStageChanged $event) use ($result, $fromStageId, $target): bool {
                return $event->deal->is($result)
                    && $event->fromStageId === $fromStageId
                    && $event->toStageId === (int) $target->id
                    && $event->occurredAt !== '';
            },
        );
    }

    public function test_redundant_move_does_not_dispatch_event(): void
    {
        Event::fake([DealStageChanged::class]);

        [$deal, , $user] = $this->makeDeal();
        $service = $this->serviceWithContract(null);

        $service->move($deal, (int) $deal->stage_id, $user->id);

        Event::assertNotDispatched(DealStageChanged::class);
    }

    public function test_leaving_lost_clears_reason(): void
    {
        $pipeline = Pipeline::factory()->create();
        $lost = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'lost', 'is_lost' => true, 'sort_order' => 1]);
        $open = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'code' => 'open', 'sort_order' => 2]);
        $reason = LostReason::factory()->create();
        $user = User::factory()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $lost->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => $user->id,
            'lost_reason_id' => $reason->id,
            'closed_at' => now(),
        ]);
        $service = $this->serviceWithContract(null);

        $result = $service->move($deal, $open->id, $user->id);

        $this->assertNull($result->lost_reason_id);
        $this->assertNull($result->closed_at);
    }
}
