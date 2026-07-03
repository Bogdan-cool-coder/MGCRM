<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\MotivationCardStatus;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\MotivationCardItem;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for GET /api/motivation/cards/me (contract §6.1 / §8).
 */
class MotivationCabinetTest extends TestCase
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

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertUnauthorized();
    }

    public function test_manager_sees_own_card_empty_state_when_none_exists(): void
    {
        $manager = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/motivation/cards/me?year=2026&month=4')
            ->assertOk()
            ->assertJsonPath('meta.has_card', false)
            ->assertJsonPath('items', []);
    }

    public function test_manager_403_on_other_user_id(): void
    {
        $manager = $this->makeManager();
        $other = $this->makeManager();
        Sanctum::actingAs($manager, ['*']);

        $this->getJson("/api/motivation/cards/me?year=2026&month=4&user_id={$other->id}")
            ->assertForbidden();
    }

    public function test_director_can_view_another_user(): void
    {
        $director = $this->makeDirector();
        $manager = $this->makeManager();
        Sanctum::actingAs($director, ['*']);

        $this->getJson("/api/motivation/cards/me?year=2026&month=4&user_id={$manager->id}")
            ->assertOk()
            ->assertJsonPath('meta.has_card', false);
    }

    public function test_admin_can_view_any_user(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeManager();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/motivation/cards/me?year=2026&month=4&user_id={$manager->id}")
            ->assertOk();
    }

    public function test_full_card_response_structure(): void
    {
        $manager = $this->makeManager();
        $pipeline = Pipeline::factory()->create();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'pipeline_id' => $pipeline->id,
            'period_year' => 2026,
            'period_month' => 4,
            'base_currency' => 'RUB',
        ]);

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'name' => 'Оклад',
            'plan_amount_kopecks' => 1_700_000_00,
            'fact_amount_kopecks' => 1_700_000_00,
            'salary_plan_kopecks' => 1_700_000_00,
            'salary_fact_kopecks' => 1_700_000_00,
            'currency' => 'RUB',
            'sort' => 0,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/motivation/cards/me?year=2026&month=4')
            ->assertOk()
            ->assertJsonStructure([
                'meta' => ['user', 'supervisor', 'pipeline', 'period', 'status', 'base_currency', 'fact_source', 'multi_currency_warning', 'has_card'],
                'dept_plan' => ['target_kopecks', 'target_currency', 'fact_kopecks', 'pct', 'badge'],
                'items' => [['kind', 'name', 'sort', 'plan_amount_kopecks', 'fact_amount_kopecks', 'currency', 'salary_plan_kopecks', 'salary_fact_kopecks', 'pct', 'badge', 'params']],
                'total' => ['salary_plan_kopecks', 'salary_fact_kopecks', 'currency'],
                'rates' => ['date', 'source', 'pairs'],
            ])
            ->assertJsonPath('meta.has_card', true)
            ->assertJsonPath('meta.status', 'draft')
            ->assertJsonPath('meta.fact_source', 'won_deals')
            ->assertJsonPath('total.salary_plan_kopecks', 1_700_000_00)
            ->assertJsonPath('total.salary_fact_kopecks', 1_700_000_00);
    }

    public function test_kpi_manual_item_has_null_pct(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        MotivationCardItem::factory()->kpi('manual')->create([
            'motivation_card_id' => $card->id,
            'params' => ['kpi_type' => 'manual', 'manual_done' => true],
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $items = $response->json('items');
        $this->assertNull($items[0]['pct']);
        $this->assertTrue($items[0]['params']['manual_done']);
    }

    public function test_kpi_count_item_computes_pct(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        MotivationCardItem::factory()->kpi('count')->create([
            'motivation_card_id' => $card->id,
            'params' => ['kpi_type' => 'count', 'unit' => 'meetings', 'plan_count' => 10, 'fact_count' => 12],
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $this->assertSame(120, $response->json('items.0.pct'));
    }

    public function test_finalized_card_status_reflected_in_cabinet(): void
    {
        $manager = $this->makeManager();

        MotivationCard::factory()->finalized()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/motivation/cards/me?year=2026&month=4')
            ->assertOk()
            ->assertJsonPath('meta.status', MotivationCardStatus::Finalized->value);
    }
}
