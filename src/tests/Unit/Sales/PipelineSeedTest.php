<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_pipeline_has_11_stages_in_order(): void
    {
        $this->seed(PipelineSeeder::class);

        $pipeline = Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();

        $this->assertCount(11, $pipeline->stages);

        $codes = $pipeline->stages->sortBy('sort_order')->pluck('code')->all();
        $this->assertSame(
            ['lost', 'new', 'qualify', 'schedule_meeting', 'meeting', 'cold', 'warm', 'hot', 'won', 'await_payment', 'paid'],
            $codes,
        );
    }

    public function test_stage_codes_match_locked_list(): void
    {
        $this->seed(PipelineSeeder::class);

        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();
        foreach (['lost', 'new', 'qualify', 'schedule_meeting', 'meeting', 'cold', 'warm', 'hot', 'won', 'await_payment', 'paid'] as $code) {
            $this->assertDatabaseHas('pipeline_stages', ['pipeline_id' => $pipeline->id, 'code' => $code]);
        }
    }

    public function test_won_and_lost_flags_correct(): void
    {
        $this->seed(PipelineSeeder::class);
        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();

        $lost = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', 'lost')->firstOrFail();
        $this->assertTrue($lost->is_lost);
        $this->assertTrue($lost->hidden_by_default);

        $won = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', 'won')->firstOrFail();
        $this->assertTrue($won->is_won);
        $this->assertTrue($won->won_gate);

        foreach (['await_payment', 'paid'] as $code) {
            $stage = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', $code)->firstOrFail();
            $this->assertTrue($stage->is_won);
        }
    }

    public function test_parent_stage_resolved_for_substages(): void
    {
        $this->seed(PipelineSeeder::class);
        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();

        $won = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', 'won')->firstOrFail();

        foreach (['await_payment', 'paid'] as $code) {
            $stage = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', $code)->firstOrFail();
            $this->assertSame($won->id, (int) $stage->parent_stage_id);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(PipelineSeeder::class);
        $this->seed(PipelineSeeder::class);

        $this->assertSame(1, Pipeline::where('name', 'Продажи')->count());
        $pipeline = Pipeline::where('name', 'Продажи')->firstOrFail();
        $this->assertSame(11, PipelineStage::where('pipeline_id', $pipeline->id)->count());
    }
}
