<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\SalaryPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryPlan>
 */
class SalaryPlanFactory extends Factory
{
    protected $model = SalaryPlan::class;

    public function definition(): array
    {
        $now = now();

        return [
            'user_id' => fn () => User::factory(),
            'period_year' => $now->year,
            'period_month' => $now->month,
            'personal_income_plan_kopecks' => 20_000_000, // 200 000 RUB in kopecks
            'personal_income_plan_currency' => 'RUB',
            'personal_ftm_plan' => 5,
            'team_target_id' => null,
            'commission_rule_id' => null,
            'status' => 'draft',
        ];
    }

    /**
     * State: specific period (year+month).
     */
    public function forPeriod(int $year, int $month): static
    {
        return $this->state([
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }

    /**
     * State: finalized plan.
     */
    public function finalized(): static
    {
        return $this->state(['status' => 'finalized']);
    }

    /**
     * State: no FTM plan set.
     */
    public function noFtmPlan(): static
    {
        return $this->state(['personal_ftm_plan' => null]);
    }
}
