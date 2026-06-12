<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HD3 (S1.9): DashboardResource is unwrapped via a per-CLASS static $wrap = null
 * override — NOT via withoutWrapping() in the constructor (which mutated the
 * shared JsonResource::$wrap and globally unwrapped every other resource). This
 * test proves the dashboard is unwrapped while DealResource stays wrapped under
 * `data` within the same test process.
 */
class DashboardResourceWrappingTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function salesPipeline(): Pipeline
    {
        return Pipeline::factory()->create([
            'kind' => PipelineKind::Sales->value,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_dashboard_unwrapped_but_deal_resource_still_wrapped(): void
    {
        $user = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 1,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Dashboard: top-level keys, no `data` envelope.
        $dashboard = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $this->assertArrayNotHasKey('data', $dashboard->json());
        $this->assertArrayHasKey('meta', $dashboard->json());
        $this->assertArrayHasKey('status_groups', $dashboard->json());

        // A Deal endpoint hit in the SAME process must remain wrapped under `data`.
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $user->id,
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id);
    }

    public function test_deal_then_dashboard_order_keeps_wrapping_independent(): void
    {
        // Reverse order: hit a wrapped resource first, then the dashboard.
        $user = User::factory()->create(['role' => Role::Director]);
        $pipeline = $this->salesPipeline();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'sort_order' => 1,
        ]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_user_id' => $user->id,
            'company_id' => Company::factory()->create()->id,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id);

        $dashboard = $this->getJson('/api/sales/dashboard?pipeline_id='.$pipeline->id)
            ->assertOk();

        $this->assertArrayNotHasKey('data', $dashboard->json());
        $this->assertArrayHasKey('meta', $dashboard->json());
    }
}
