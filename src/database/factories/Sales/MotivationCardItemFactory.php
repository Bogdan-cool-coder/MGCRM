<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Sales\Enums\MotivationCardItemKind;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\MotivationCardItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationCardItem>
 */
class MotivationCardItemFactory extends Factory
{
    protected $model = MotivationCardItem::class;

    public function definition(): array
    {
        return [
            'motivation_card_id' => fn () => MotivationCard::factory(),
            'kind' => MotivationCardItemKind::BaseSalary,
            'name' => 'Оклад',
            'plan_amount_kopecks' => 0,
            'fact_amount_kopecks' => 0,
            'salary_plan_kopecks' => 0,
            'salary_fact_kopecks' => 0,
            'currency' => 'RUB',
            'params' => [],
            'sort' => 0,
        ];
    }

    public function commission(int $ratePctTimes100 = 1000): static
    {
        return $this->state([
            'kind' => MotivationCardItemKind::Commission,
            'name' => 'Комиссия',
            'params' => ['rate_pct_times_100' => $ratePctTimes100],
        ]);
    }

    public function kpi(string $kpiType = 'count'): static
    {
        return $this->state([
            'kind' => MotivationCardItemKind::Kpi,
            'name' => 'KPI',
            'params' => ['kpi_type' => $kpiType],
        ]);
    }

    public function teamKpi(): static
    {
        return $this->state([
            'kind' => MotivationCardItemKind::TeamKpi,
            'name' => 'Командный бонус',
        ]);
    }
}
