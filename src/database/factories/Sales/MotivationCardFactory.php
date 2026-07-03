<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\FactSourceKind;
use App\Domain\Sales\Enums\MotivationCardStatus;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotivationCard>
 */
class MotivationCardFactory extends Factory
{
    protected $model = MotivationCard::class;

    public function definition(): array
    {
        $now = now();

        return [
            'user_id' => fn () => User::factory(),
            'pipeline_id' => fn () => Pipeline::factory(),
            'period_year' => $now->year,
            'period_month' => $now->month,
            'status' => MotivationCardStatus::Draft,
            'base_currency' => 'RUB',
            'fact_source' => FactSourceKind::WonDeals,
            'supervisor_user_id' => null,
        ];
    }

    public function forPeriod(int $year, int $month): static
    {
        return $this->state([
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }

    public function finalized(): static
    {
        return $this->state([
            'status' => MotivationCardStatus::Finalized,
            'finalized_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state([
            'status' => MotivationCardStatus::Paid,
            'finalized_at' => now(),
            'paid_at' => now(),
        ]);
    }
}
