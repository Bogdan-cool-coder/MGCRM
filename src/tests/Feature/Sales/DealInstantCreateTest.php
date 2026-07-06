<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deal Create 2.0 — instant-create server defaults (docs/specs/deal-create-2-contract.md
 * §1.3): pipeline_id is the only required field, everything else gets a
 * server-side default.
 */
class DealInstantCreateTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_instant_create_with_only_pipeline_id_gets_default_title(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Новая сделка');
    }

    public function test_instant_create_blank_title_falls_back_to_default(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id, 'title' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Новая сделка');
    }

    public function test_instant_create_sets_owner_to_auth_user(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.owner_user_id', $user->id);
    }

    public function test_instant_create_currency_falls_back_to_configured_default(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.currency', config('crm.currencies.default', 'RUB'));
    }

    public function test_instant_create_currency_derives_from_company_country(): void
    {
        // KZ is mapped to KZT in config('crm.currencies.by_country').
        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', [
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'KZT');
    }

    public function test_instant_create_explicit_currency_wins_over_country(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create(['country_code' => 'kz']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', [
            'pipeline_id' => $pipeline->id,
            'company_id' => $company->id,
            'currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_instant_create_uses_pipeline_default_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $qualifyStageId = $this->stageCode($pipeline, 'qualify');
        $pipeline->update(['default_stage_id' => $qualifyStageId]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.stage_id', $qualifyStageId);
    }

    public function test_default_stage_pointing_to_won_stage_falls_back_to_first_stage(): void
    {
        // Guard (§1.3 point 4 / §3.1): a default_stage_id pointing at a won/lost/
        // hidden stage is ignored — the service falls back to the normal rule.
        $pipeline = $this->seedSalesPipeline();
        $wonStageId = $this->stageCode($pipeline, 'won');

        // Bypass FormRequest validation (which only checks pipeline membership,
        // not won/lost/hidden) to simulate a stage later re-flagged terminal —
        // directly setting the column mirrors that scenario.
        $pipeline->update(['default_stage_id' => $wonStageId]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.stage_id', $this->stageCode($pipeline, 'new'));
    }

    public function test_default_stage_pointing_to_hidden_stage_falls_back_to_first_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $coldStageId = $this->stageCode($pipeline, 'cold'); // hidden_by_default = true

        $pipeline->update(['default_stage_id' => $coldStageId]);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.stage_id', $this->stageCode($pipeline, 'new'));
    }

    public function test_no_default_stage_falls_back_to_first_non_terminal_stage(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $this->assertNull($pipeline->default_stage_id);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])
            ->assertCreated()
            ->assertJsonPath('data.stage_id', $this->stageCode($pipeline, 'new'));
    }

    public function test_store_response_eager_loads_pipeline_stage_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $response = $this->postJson('/api/deals', ['pipeline_id' => $pipeline->id])->assertCreated();

        $response->assertJsonPath('data.pipeline.name', 'Продажи');
        $this->assertNotNull($response->json('data.stage.name'));
        $this->assertNotNull($response->json('data.owner.name'));
    }
}
