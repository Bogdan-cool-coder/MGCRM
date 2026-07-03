<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\TeamKpiRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamKpiRule>
 */
class TeamKpiRuleFactory extends Factory
{
    protected $model = TeamKpiRule::class;

    public function definition(): array
    {
        $now = now();

        return [
            'pipeline_id' => fn () => Pipeline::factory(),
            'period_year' => $now->year,
            'period_month' => $now->month,
            'team_income_target_kopecks' => 80_000_000, // 80,000,000 kopecks = 800,000 RUB (contract §6.1 example)
            'target_currency' => 'RUB',
            'base_pool_kopecks' => 50_000_000,
            'per_extra_member_kopecks' => 10_000_000,
            'min_members' => 2,
            'pool_currency' => 'RUB',
            'split_contribution_pct' => 60,
            'split_equal_pct' => 40,
            'min_threshold_pct' => 80,
        ];
    }

    public function forPeriod(int $year, int $month): static
    {
        return $this->state([
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }
}
