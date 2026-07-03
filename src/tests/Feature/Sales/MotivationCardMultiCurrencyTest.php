<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\MotivationCardItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for multi-currency conversion in the cabinet payload (contract
 * §4.4 / §8): each item carries its own currency, converted to
 * card.base_currency via ExchangeRateService using the 1st-of-month rate.
 * Missing rate → multi_currency_warning=true + raw-amount fallback.
 */
class MotivationCardMultiCurrencyTest extends TestCase
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

    public function test_item_currency_converted_to_base_currency(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
            'base_currency' => 'UZS',
        ]);

        // 151 UZS per 1 RUB (contract §6.1 example rate).
        $this->seedRate('RUB', 'UZS', 151.0, '2026-04-01');

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'currency' => 'RUB',
            'salary_plan_kopecks' => 100_000, // 1000 RUB
            'salary_fact_kopecks' => 100_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $response->assertJsonPath('meta.multi_currency_warning', false);
        // 100_000 kop RUB * 151 = 15_100_000 kop UZS.
        $this->assertSame(15_100_000, $response->json('items.0.salary_plan_kopecks'));
        $this->assertSame('RUB', $response->json('items.0.currency'));
    }

    public function test_missing_rate_sets_warning_and_falls_back_to_raw_amount(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
            'base_currency' => 'UZS',
        ]);

        // No AED→UZS rate seeded.
        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'kind' => 'base_salary',
            'currency' => 'AED',
            'salary_plan_kopecks' => 50_000,
            'salary_fact_kopecks' => 50_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $response->assertJsonPath('meta.multi_currency_warning', true);
        // Falls back to the raw (unconverted) amount rather than collapsing to 0.
        $this->assertSame(50_000, $response->json('items.0.salary_plan_kopecks'));
    }

    public function test_same_currency_as_base_needs_no_conversion(): void
    {
        $manager = $this->makeManager();

        $card = MotivationCard::factory()->create([
            'user_id' => $manager->id,
            'period_year' => 2026,
            'period_month' => 4,
            'base_currency' => 'RUB',
        ]);

        MotivationCardItem::factory()->create([
            'motivation_card_id' => $card->id,
            'currency' => 'RUB',
            'salary_plan_kopecks' => 250_000,
            'salary_fact_kopecks' => 250_000,
        ]);

        Sanctum::actingAs($manager, ['*']);

        $response = $this->getJson('/api/motivation/cards/me?year=2026&month=4')->assertOk();

        $response->assertJsonPath('meta.multi_currency_warning', false);
        $this->assertSame(250_000, $response->json('items.0.salary_plan_kopecks'));
    }
}
