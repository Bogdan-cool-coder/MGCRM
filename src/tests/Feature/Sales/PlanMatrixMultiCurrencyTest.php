<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for multi-currency plan/fact aggregation in the matrix
 * (contract §4.1): a won deal denominated in a foreign currency is converted
 * to base (RUB) at the 1st-of-the-deal's-month rate before being compared
 * against the plan. Missing rate → multi_currency_warning=true + raw fallback.
 */
class PlanMatrixMultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        return User::factory()->create(['role' => Role::Manager, 'is_active' => true]);
    }

    private function seedRate(string $from, string $to, float $rate, string $date): void
    {
        ExchangeRate::create([
            'from_code' => strtoupper($from),
            'to_code' => strtoupper($to),
            'rate' => $rate,
            'date' => $date,
        ]);
    }

    public function test_foreign_currency_won_deal_converted_to_base_at_month_rate(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        // Exact-dividing rate (100 UZS per 1 RUB) so the assertion is not
        // sensitive to decimal(20,6) rounding noise — the rounding behaviour
        // itself is covered by ExchangeRateService's own unit tests.
        $this->seedRate('UZS', 'RUB', 0.01, '2026-03-01');

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00, // 100 000 UZS
            'currency' => 'UZS',
            'stage_changed_at' => '2026-03-10 12:00:00',
        ]);

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 3)->create([
            'value_kopecks' => 1_000_00, // 1000 RUB plan
            'currency' => 'RUB',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        // 100 000 UZS * 0.01 = 1 000 RUB.
        $this->assertSame(1_000_00, $ownRow['cells']['3']['fact_kopecks']);
        $this->assertFalse($response->json('meta.multi_currency_warning'));
    }

    public function test_missing_rate_sets_multi_currency_warning_and_skips_the_deal(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_000_00,
            'currency' => 'AED', // no rate seeded
            'stage_changed_at' => '2026-05-05 09:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $this->assertTrue($response->json('meta.multi_currency_warning'));

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);
        $this->assertSame(0, $ownRow['cells']['5']['fact_kopecks'], 'a deal with no available rate must be skipped, not counted raw');
    }

    public function test_annual_sum_converts_each_month_at_its_own_rate(): void
    {
        $manager = $this->makeManager();
        $stage = PipelineStage::factory()->won()->create();

        // Different rate per month — proves each month converts independently
        // rather than the annual total using a single blended rate.
        $this->seedRate('UZS', 'RUB', 1 / 100, '2026-01-01');
        $this->seedRate('UZS', 'RUB', 1 / 200, '2026-02-01');

        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 100_00, // 100 UZS
            'currency' => 'UZS',
            'stage_changed_at' => '2026-01-15 00:00:00',
        ]);
        Deal::factory()->forOwner($manager)->inStage($stage)->create([
            'amount' => 200_00, // 200 UZS
            'currency' => 'UZS',
            'stage_changed_at' => '2026-02-15 00:00:00',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        // Jan: 100 UZS / 100 = 1 RUB kopeck-equivalent (1_00 in our test's smaller unit).
        $this->assertSame(1_00, $ownRow['cells']['1']['fact_kopecks']);
        // Feb: 200 UZS / 200 = 1 RUB.
        $this->assertSame(1_00, $ownRow['cells']['2']['fact_kopecks']);
        // Annual = sum of independently-converted months = 2.
        $this->assertSame(2_00, $ownRow['cells']['annual']['fact_kopecks']);
    }
}
