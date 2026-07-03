<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the plan-side FX conversion fix (contract §4.1 known-gap):
 * a plan cell stored in a non-base currency is converted to base BEFORE the
 * plan/fact percentage is scored, symmetric with the fact-side conversion
 * already covered by PlanMatrixMultiCurrencyTest.
 */
class PlanMatrixPlanFxTest extends TestCase
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

    public function test_eur_denominated_plan_is_converted_to_base_before_scoring(): void
    {
        $manager = $this->makeManager();

        // 1 EUR = 100 RUB on 2026-04-01.
        $this->seedRate('EUR', 'RUB', 100, '2026-04-01');

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 4)->create([
            'value_kopecks' => 1_000_00, // 1000 EUR
            'currency' => 'EUR',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        // 1000 EUR * 100 = 100 000 RUB, in kopecks: 1000_00 * 100 = 100_000_00.
        $this->assertSame(100_000_00, $ownRow['cells']['4']['plan_kopecks']);
        $this->assertFalse($response->json('meta.multi_currency_warning'));

        // currency_breakdown still carries the ORIGINAL stored amount/currency.
        $this->assertSame('EUR', $ownRow['cells']['4']['currency_breakdown'][0]['currency']);
        $this->assertSame(1_000_00, $ownRow['cells']['4']['currency_breakdown'][0]['plan_kopecks']);
    }

    public function test_missing_plan_rate_sets_multi_currency_warning_and_falls_back_to_raw(): void
    {
        $manager = $this->makeManager();

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, 6)->create([
            'value_kopecks' => 500_00,
            'currency' => 'AED', // no rate seeded
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=operative&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        $this->assertTrue($response->json('meta.multi_currency_warning'));
        // Raw fallback: plan_kopecks stays the unconverted stored value.
        $this->assertSame(500_00, $ownRow['cells']['6']['plan_kopecks']);
    }

    public function test_annual_standalone_cell_converts_at_january_first_rate(): void
    {
        $manager = $this->makeManager();

        $this->seedRate('USD', 'RUB', 90, '2026-01-01');

        PlanTarget::factory()->forUser($manager->id)->forPeriod(2026, null)->annual()->create([
            'value_kopecks' => 10_000_00, // 10 000 USD
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/plans/matrix?metric=new_income&scope_type=user&layer=annual&year=2026')
            ->assertOk();

        $ownRow = collect($response->json('rows'))->firstWhere('scope.id', $manager->id);

        // 10 000 USD * 90 = 900 000 RUB.
        $this->assertSame(900_000_00, $ownRow['cells']['annual']['plan_kopecks']);
    }
}
