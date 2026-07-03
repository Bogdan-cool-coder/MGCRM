<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\MotivationCardItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fix #35 regression: MotivationCardService::computeItemRow only ever derived
 * salary_fact for commission — salary_plan was NEVER derived for ANY kind, so
 * an item saved with only plan_amount_kopecks filled (the normal constructor
 * path) rendered salary_plan=0 in the cabinet, collapsing the "ЗП план" total
 * even with a fully-filled оклад. Manual entry (a non-zero stored column)
 * must still win over every derive rule below.
 */
class MotivationCardSalaryPlanDeriveTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function makeCard(): MotivationCard
    {
        return MotivationCard::factory()->create([
            'user_id' => $this->manager->id,
            'period_year' => 2026,
            'period_month' => 4,
            'base_currency' => 'RUB',
        ]);
    }

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->makeManager();
    }

    private function fetchItem(): array
    {
        Sanctum::actingAs($this->manager, ['*']);
        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        return $response->json('items.0');
    }

    // -------------------------------------------------------------------------
    // base_salary — оклад 30 000 → итог plan=30 000 (эталон из тикета)
    // -------------------------------------------------------------------------

    public function test_base_salary_derives_plan_and_fact_from_plan_amount(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'name' => 'Оклад',
            'plan_amount_kopecks' => 30_000_00,
            // salary_plan / salary_fact left at 0 — the normal constructor save.
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
        ]);

        $item = $this->fetchItem();

        $this->assertSame(30_000_00, $item['salary_plan_kopecks']);
        $this->assertSame(30_000_00, $item['salary_fact_kopecks']);
    }

    public function test_base_salary_manual_override_wins_over_derive(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'plan_amount_kopecks' => 30_000_00,
            'salary_plan_kopecks' => 25_000_00, // manual override, non-zero
            'salary_fact_kopecks' => 20_000_00, // manual override, non-zero
        ]);

        $item = $this->fetchItem();

        $this->assertSame(25_000_00, $item['salary_plan_kopecks']);
        $this->assertSame(20_000_00, $item['salary_fact_kopecks']);
    }

    // -------------------------------------------------------------------------
    // bonus — same "always paid" derive as base_salary
    // -------------------------------------------------------------------------

    public function test_bonus_derives_plan_and_fact_from_plan_amount(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'bonus',
            'plan_amount_kopecks' => 10_000_00,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
            'params' => ['reason' => 'Разовая премия'],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(10_000_00, $item['salary_plan_kopecks']);
        $this->assertSame(10_000_00, $item['salary_fact_kopecks']);
    }

    // -------------------------------------------------------------------------
    // commission — salary_plan = rate x plan indicator (plan-side, new);
    // salary_fact = rate x fact indicator (fact-side, pre-existing behavior).
    // -------------------------------------------------------------------------

    public function test_commission_derives_salary_plan_from_plan_amount_and_rate(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'commission',
            'plan_amount_kopecks' => 600_000_00, // 600 000 RUB plan indicator
            'fact_amount_kopecks' => 250_000_00, // manual fact override, avoids a live won-deals lookup
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
            'currency' => 'RUB',
            'params' => ['rate_pct_times_100' => 1000], // 10%
        ]);

        $item = $this->fetchItem();

        // 10% of 600 000 00 kop plan → 60 000 00 kop.
        $this->assertSame(60_000_00, $item['salary_plan_kopecks']);
        // 10% of 250 000 00 kop fact → 25 000 00 kop (pre-existing fact-side derive, unaffected).
        $this->assertSame(25_000_00, $item['salary_fact_kopecks']);
    }

    public function test_commission_manual_salary_plan_override_wins(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'commission',
            'plan_amount_kopecks' => 600_000_00,
            'fact_amount_kopecks' => 250_000_00,
            'salary_plan_kopecks' => 99_999_00, // manual override, non-zero
            'salary_fact_kopecks' => 0,
            'currency' => 'RUB',
            'params' => ['rate_pct_times_100' => 1000],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(99_999_00, $item['salary_plan_kopecks']);
    }

    // -------------------------------------------------------------------------
    // kpi count — salary_plan = plan_count x salary_per_completion_kopecks
    // -------------------------------------------------------------------------

    public function test_kpi_count_derives_salary_plan_from_plan_count_times_rate(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->kpi('count')->create([
            'motivation_card_id' => $card->id,
            'salary_plan_kopecks' => 0,
            'params' => ['kpi_type' => 'count', 'unit' => 'meetings', 'plan_count' => 10, 'fact_count' => 12, 'salary_per_completion_kopecks' => 5_000_00],
        ]);

        $item = $this->fetchItem();

        // 10 meetings * 5 000 00 kop each = 50 000 00 kop.
        $this->assertSame(50_000_00, $item['salary_plan_kopecks']);
    }

    public function test_kpi_count_manual_salary_plan_override_wins(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->kpi('count')->create([
            'motivation_card_id' => $card->id,
            'salary_plan_kopecks' => 42_000_00, // manual override, non-zero
            'params' => ['kpi_type' => 'count', 'plan_count' => 10, 'salary_per_completion_kopecks' => 5_000_00],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(42_000_00, $item['salary_plan_kopecks']);
    }

    // -------------------------------------------------------------------------
    // kpi manual — salary_plan = the full completion bonus; salary_fact only
    // pays out when manual_done=true.
    // -------------------------------------------------------------------------

    public function test_kpi_manual_derives_salary_plan_as_full_completion_bonus(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->kpi('manual')->create([
            'motivation_card_id' => $card->id,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
            'params' => ['kpi_type' => 'manual', 'manual_done' => false, 'salary_per_completion_kopecks' => 15_000_00],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(15_000_00, $item['salary_plan_kopecks']);
        // Not done yet → fact stays 0.
        $this->assertSame(0, $item['salary_fact_kopecks']);
    }

    public function test_kpi_manual_done_derives_salary_fact_as_full_completion_bonus(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->kpi('manual')->create([
            'motivation_card_id' => $card->id,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
            'params' => ['kpi_type' => 'manual', 'manual_done' => true, 'salary_per_completion_kopecks' => 15_000_00],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(15_000_00, $item['salary_plan_kopecks']);
        $this->assertSame(15_000_00, $item['salary_fact_kopecks']);
    }

    // -------------------------------------------------------------------------
    // kpi amount — salary_plan = plan_amount_kopecks (money-denominated indicator)
    // -------------------------------------------------------------------------

    public function test_kpi_amount_derives_salary_plan_from_plan_amount(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->kpi('amount')->create([
            'motivation_card_id' => $card->id,
            'plan_amount_kopecks' => 20_000_00,
            'salary_plan_kopecks' => 0,
            'params' => ['kpi_type' => 'amount'],
        ]);

        $item = $this->fetchItem();

        $this->assertSame(20_000_00, $item['salary_plan_kopecks']);
    }

    // -------------------------------------------------------------------------
    // team_kpi — must NOT be touched by this fix (own pool/gate computation).
    // -------------------------------------------------------------------------

    public function test_team_kpi_is_not_affected_by_the_derive_fix(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->teamKpi()->create([
            'motivation_card_id' => $card->id,
            'plan_amount_kopecks' => 40_000_00,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
        ]);

        $item = $this->fetchItem();

        // No derive branch matches team_kpi — stays exactly as stored (0), same
        // as pre-fix behavior. The real payout for team_kpi comes from the
        // separate team_bonus_forecast block, not this per-item derive.
        $this->assertSame(0, $item['salary_plan_kopecks']);
        $this->assertSame(0, $item['salary_fact_kopecks']);
    }

    // -------------------------------------------------------------------------
    // End-to-end: total.salary_plan_kopecks reflects the derived оклад (the
    // exact symptom reported in #35 — "итог ЗП план=0 при заполненном окладе").
    // -------------------------------------------------------------------------

    public function test_cabinet_total_reflects_derived_base_salary_plan(): void
    {
        $card = $this->makeCard();

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'plan_amount_kopecks' => 30_000_00,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
        ]);

        Sanctum::actingAs($this->manager, ['*']);
        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $this->assertSame(30_000_00, $response->json('total.salary_plan_kopecks'));
    }
}
