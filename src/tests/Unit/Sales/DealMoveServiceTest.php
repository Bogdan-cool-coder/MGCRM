<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DealMoveServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_redundant_move_is_noop(): void
    {
        [$deal, , $user] = $this->makeDeal();
        $service = app(DealMoveService::class);

        $result = $service->move($deal, (int) $deal->stage_id, $user->id);

        $this->assertFalse($result['won_gate_warning']);
        $this->assertDatabaseCount('deal_stage_history', 0);
    }

    public function test_lost_gate_blocks_without_reason(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_lost' => true]);
        $service = app(DealMoveService::class);

        $this->expectException(ValidationException::class);
        $service->move($deal, $target->id, $user->id);
    }

    public function test_lost_move_with_reason_sets_closed_at_and_history(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_lost' => true]);
        $reason = LostReason::factory()->create();
        $service = app(DealMoveService::class);

        $result = $service->move($deal, $target->id, $user->id, null, $reason->id);

        $this->assertNotNull($result['deal']->closed_at);
        $this->assertSame($reason->id, (int) $result['deal']->lost_reason_id);
        $this->assertDatabaseCount('deal_stage_history', 1);
    }

    public function test_won_gate_warning_when_no_contract(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => true]);
        $service = app(DealMoveService::class);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertTrue($result['won_gate_warning']);
        $this->assertNotNull($result['deal']->closed_at);
    }

    public function test_won_gate_no_warning_with_contract(): void
    {
        [$deal, $target, $user] = $this->makeDeal(targetState: fn (array $s): array => $s + ['is_won' => true, 'won_gate' => true]);
        $deal->update(['contract_id' => 123]);
        $service = app(DealMoveService::class);

        $result = $service->move($deal, $target->id, $user->id);

        $this->assertFalse($result['won_gate_warning']);
    }

    public function test_move_to_foreign_pipeline_stage_rejected(): void
    {
        [$deal, , $user] = $this->makeDeal();
        $foreignPipeline = Pipeline::factory()->create();
        $foreignStage = PipelineStage::factory()->create(['pipeline_id' => $foreignPipeline->id]);
        $service = app(DealMoveService::class);

        $this->expectException(ValidationException::class);
        $service->move($deal, $foreignStage->id, $user->id);
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
        $service = app(DealMoveService::class);

        $result = $service->move($deal, $open->id, $user->id);

        $this->assertNull($result['deal']->lost_reason_id);
        $this->assertNull($result['deal']->closed_at);
    }
}
