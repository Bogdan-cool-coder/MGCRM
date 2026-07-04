<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\TeamKpiRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit fix (2026-07-04): WonDealsFactSource::teamContributions ignored the
 * $pipelineId argument entirely, so the МК team-bonus pool's dept_plan.fact_kopecks
 * summed EVERY pipeline's won deals for the team's member cohort instead of just
 * the ONE pipeline the card's TeamKpiRule is scoped to.
 */
class MotivationCardTeamPipelineScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    public function test_dept_plan_fact_only_counts_the_cards_own_pipeline(): void
    {
        $manager = $this->makeManager();
        $colleague = $this->makeManager();

        $pipelineA = Pipeline::factory()->create();
        $pipelineB = Pipeline::factory()->create();

        $wonStageA = PipelineStage::factory()->won()->create(['pipeline_id' => $pipelineA->id]);
        $wonStageB = PipelineStage::factory()->won()->create(['pipeline_id' => $pipelineB->id]);

        // Team rule + cards scope this team to pipeline A only.
        TeamKpiRule::factory()->create([
            'pipeline_id' => $pipelineA->id,
            'period_year' => 2026,
            'period_month' => 4,
            'team_income_target_kopecks' => 100_000_00,
        ]);

        MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'pipeline_id' => $pipelineA->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);
        MotivationCard::factory()->create([
            'user_id' => $colleague->id,
            'pipeline_id' => $pipelineA->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        // Pipeline A won deal (must count).
        Deal::factory()->forOwner($manager)->inStage($wonStageA)->create([
            'amount' => 40_000_00,
            'currency' => 'RUB',
            'stage_changed_at' => '2026-04-10',
        ]);

        // Pipeline B won deal by the SAME manager (must NOT count — wrong pipeline).
        Deal::factory()->forOwner($manager)->inStage($wonStageB)->create([
            'amount' => 900_000_00,
            'currency' => 'RUB',
            'stage_changed_at' => '2026-04-11',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        // Only the pipeline-A deal (40_000_00) should count — the pipeline-B
        // deal (900_000_00) must never leak into the team fact.
        $response->assertJsonPath('dept_plan.fact_kopecks', 40_000_00);
    }
}
