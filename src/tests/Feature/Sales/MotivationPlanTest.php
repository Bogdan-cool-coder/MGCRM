<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Motivation Card constructor (contract §6.2 / §6.3 / §8):
 * GET/POST /api/admin/motivation/plans, copy-previous, finalize, mark-paid.
 */
class MotivationPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeDirector(): User
    {
        return User::factory()->create(['role' => Role::Director, 'is_active' => true]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
    }

    private function makeAccountant(): User
    {
        return User::factory()->create(['role' => Role::Accountant, 'is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(int $userId, ?int $pipelineId = null): array
    {
        return [
            'user_id' => $userId,
            'year' => 2026,
            'month' => 4,
            'pipeline_id' => $pipelineId,
            'base_currency' => 'RUB',
            'items' => [
                [
                    'kind' => 'base_salary',
                    'name' => 'Оклад',
                    'plan_amount_kopecks' => 1_700_000_00,
                    'salary_plan_kopecks' => 1_700_000_00,
                    'currency' => 'RUB',
                    'params' => ['payment_note' => 'next_month'],
                ],
                [
                    'kind' => 'commission',
                    'name' => 'Комиссия',
                    'plan_amount_kopecks' => 600_000_00,
                    'currency' => 'RUB',
                    'params' => ['rate_pct_times_100' => 1000],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // POST /api/admin/motivation/plans — authorization
    // -------------------------------------------------------------------------

    public function test_manager_403_creating_plan(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))
            ->assertForbidden();
    }

    public function test_admin_creates_plan_201(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))
            ->assertCreated();

        $response->assertJsonPath('data.status', 'draft');
        $this->assertSame(2, count($response->json('data.items')));
    }

    public function test_director_creates_plan_201(): void
    {
        $director = $this->makeDirector();
        $manager = $this->makeManager();
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))
            ->assertCreated();
    }

    public function test_upsert_is_idempotent_returns_200_on_update(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))->assertCreated();

        $updated = $this->planPayload($manager->id);
        $updated['items'][0]['plan_amount_kopecks'] = 2_000_000_00;

        $response = $this->postJson('/api/admin/motivation/plans', $updated)->assertOk();
        $response->assertJsonPath('data.items.0.plan_amount_kopecks', 2_000_000_00);

        $this->assertSame(1, MotivationCard::query()->where('user_id', $manager->id)->count());
    }

    public function test_409_on_write_to_finalized_card(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();

        MotivationCard::factory()->finalized()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))
            ->assertStatus(409);
    }

    public function test_422_on_bad_kind(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id);
        $payload['items'][0]['kind'] = 'not_a_real_kind';

        $this->postJson('/api/admin/motivation/plans', $payload)->assertStatus(422);
    }

    public function test_422_on_split_not_summing_to_100(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        $pipeline = Pipeline::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id, $pipeline->id);
        $payload['team_kpi_rule'] = [
            'pipeline_id' => $pipeline->id,
            'split_contribution_pct' => 70,
            'split_equal_pct' => 40,
        ];

        $this->postJson('/api/admin/motivation/plans', $payload)->assertStatus(422);
    }

    public function test_team_kpi_rule_persisted(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        $pipeline = Pipeline::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id, $pipeline->id);
        $payload['team_kpi_rule'] = [
            'pipeline_id' => $pipeline->id,
            'team_income_target_kopecks' => 80_000_000,
            'base_pool_kopecks' => 50_000_000,
            'per_extra_member_kopecks' => 10_000_000,
            'min_members' => 2,
            'split_contribution_pct' => 60,
            'split_equal_pct' => 40,
            'min_threshold_pct' => 80,
        ];

        $response = $this->postJson('/api/admin/motivation/plans', $payload)->assertCreated();

        $response->assertJsonPath('data.team_kpi_rule.team_income_target_kopecks', 80_000_000);
    }

    // -------------------------------------------------------------------------
    // BUG-KPI-PLAN-COUNT-LOST regression — params.* sub-keys must survive the
    // FormRequest (Laravel validated() silently drops nested keys with no rule).
    // -------------------------------------------------------------------------

    public function test_kpi_count_plan_count_survives_store_and_show(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id);
        $payload['items'][] = [
            'kind' => 'kpi',
            'name' => 'FTM встречи',
            'currency' => 'RUB',
            'params' => ['kpi_type' => 'count', 'unit' => 'meetings', 'plan_count' => 15, 'salary_per_completion_kopecks' => 0],
        ];

        $store = $this->postJson('/api/admin/motivation/plans', $payload)->assertCreated();

        $kpiItem = collect($store->json('data.items'))->firstWhere('kind', 'kpi');
        $this->assertNotNull($kpiItem, 'kpi item missing from store response');
        $this->assertSame(15, $kpiItem['params']['plan_count'], 'plan_count lost on store response');
        $this->assertSame('meetings', $kpiItem['params']['unit']);

        // Full round-trip: GET the constructor plan back and re-assert plan_count survived persistence.
        $show = $this->getJson("/api/admin/motivation/plans/{$manager->id}/2026/4")->assertOk();
        $kpiFromShow = collect($show->json('data.items'))->firstWhere('kind', 'kpi');
        $this->assertSame(15, $kpiFromShow['params']['plan_count'], 'plan_count lost on GET show after persistence');
    }

    public function test_kpi_manual_manual_done_survives_store_and_show(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id);
        $payload['items'][] = [
            'kind' => 'kpi',
            'name' => 'Отчёт сдан',
            'currency' => 'RUB',
            'params' => ['kpi_type' => 'manual', 'manual_done' => true],
        ];

        $store = $this->postJson('/api/admin/motivation/plans', $payload)->assertCreated();

        $kpiItem = collect($store->json('data.items'))->firstWhere('kind', 'kpi');
        $this->assertTrue($kpiItem['params']['manual_done'], 'manual_done lost on store response');

        $show = $this->getJson("/api/admin/motivation/plans/{$manager->id}/2026/4")->assertOk();
        $kpiFromShow = collect($show->json('data.items'))->firstWhere('kind', 'kpi');
        $this->assertTrue($kpiFromShow['params']['manual_done'], 'manual_done lost on GET show after persistence');
    }

    public function test_kpi_count_plan_count_visible_in_manager_cabinet(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id);
        $payload['items'][] = [
            'kind' => 'kpi',
            'name' => 'FTM встречи',
            'currency' => 'RUB',
            'params' => ['kpi_type' => 'count', 'unit' => 'meetings', 'plan_count' => 15, 'fact_count' => 18],
        ];

        $this->postJson('/api/admin/motivation/plans', $payload)->assertCreated();

        Sanctum::actingAs($manager, ['*']);

        $cabinet = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();
        $kpiItem = collect($cabinet->json('items'))->firstWhere('kind', 'kpi');

        $this->assertNotNull($kpiItem, 'kpi item missing from cabinet payload');
        $this->assertSame(15, $kpiItem['params']['plan_count'], 'plan_count lost by the time the cabinet reads it');
        // 18/15*100 = 120% — proves the engine computed pct from the SURVIVED plan_count, not a lost/zeroed one.
        $this->assertSame(120, $kpiItem['pct']);
    }

    public function test_kpi_plan_count_survives_copy_previous(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->planPayload($manager->id);
        $payload['items'][] = [
            'kind' => 'kpi',
            'name' => 'FTM встречи',
            'currency' => 'RUB',
            'params' => ['kpi_type' => 'count', 'unit' => 'meetings', 'plan_count' => 15],
        ];

        $this->postJson('/api/admin/motivation/plans', $payload)->assertCreated();

        $copy = $this->postJson('/api/admin/motivation/plans/copy-previous', [
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $kpiItem = collect($copy->json('data.items'))->firstWhere('kind', 'kpi');
        $this->assertNotNull($kpiItem, 'kpi item missing after copy-previous');
        $this->assertSame(15, $kpiItem['params']['plan_count'], 'plan_count lost by copy-previous');
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/motivation/plans/{userId}/{year}/{month}
    // -------------------------------------------------------------------------

    public function test_show_returns_null_data_when_no_plan(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/admin/motivation/plans/{$manager->id}/2026/4")
            ->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_show_manager_403(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson("/api/admin/motivation/plans/{$manager->id}/2026/4")
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // copy-previous
    // -------------------------------------------------------------------------

    public function test_copy_previous_clones_prior_month(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/motivation/plans', $this->planPayload($manager->id))->assertCreated();

        $response = $this->postJson('/api/admin/motivation/plans/copy-previous', [
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $response->assertJsonPath('data.month', 5);
        $this->assertSame(2, count($response->json('data.items')));
    }

    public function test_copy_previous_null_when_no_prior_card(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/motivation/plans/copy-previous', [
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 5,
        ])->assertOk()->assertJson(['data' => null]);
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function test_accountant_can_finalize(): void
    {
        $accountant = $this->makeAccountant();
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($accountant, ['*']);

        $this->postJson("/api/admin/motivation/plans/{$card->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');
    }

    public function test_director_can_finalize(): void
    {
        $director = $this->makeDirector();
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($director, ['*']);

        $this->postJson("/api/admin/motivation/plans/{$card->id}/finalize")->assertOk();
    }

    public function test_manager_403_on_finalize(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/admin/motivation/plans/{$card->id}/finalize")->assertForbidden();
    }

    public function test_finalize_then_mark_paid(): void
    {
        $accountant = $this->makeAccountant();
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($accountant, ['*']);

        $this->postJson("/api/admin/motivation/plans/{$card->id}/finalize")->assertOk();
        $this->postJson("/api/admin/motivation/plans/{$card->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_illegal_edge_returns_409(): void
    {
        $accountant = $this->makeAccountant();
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($accountant, ['*']);

        // draft → paid directly is illegal (must go through finalized first).
        $this->postJson("/api/admin/motivation/plans/{$card->id}/mark-paid")
            ->assertStatus(409);
    }

    public function test_unfinalize_denied_by_default(): void
    {
        $accountant = $this->makeAccountant();
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->finalized()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($accountant, ['*']);

        // finalize on an already-finalized card is an illegal edge (no un-finalize path exists).
        $this->postJson("/api/admin/motivation/plans/{$card->id}/finalize")
            ->assertStatus(409);
    }
}
