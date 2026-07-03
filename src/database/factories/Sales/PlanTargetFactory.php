<?php

declare(strict_types=1);

namespace Database\Factories\Sales;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Models\PlanTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanTarget>
 */
class PlanTargetFactory extends Factory
{
    protected $model = PlanTarget::class;

    public function definition(): array
    {
        $now = now();

        return [
            'metric' => PlanMetric::NewIncome,
            'scope_type' => PlanScopeType::User,
            'scope_user_id' => User::factory(),
            'scope_pipeline_id' => null,
            'scope_product_group_id' => null,
            'period_year' => $now->year,
            'period_month' => $now->month,
            'layer' => PlanLayer::Operative,
            'value_kopecks' => 10_000_000_00,
            'value_count' => null,
            'currency' => 'RUB',
            'config' => null,
            // Placeholder — overwritten by afterMaking() below once scope_user_id
            // is resolved to a concrete id, OR by an explicit forUser/forPipeline/
            // forCompany state.
            'scope_key' => 'company',
            'period_month_key' => $now->month,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PlanTarget $target): void {
            if ($target->scope_type === PlanScopeType::User && $target->scope_user_id !== null) {
                $target->scope_key = 'user:'.$target->scope_user_id;
            }
        });
    }

    public function forUser(int $userId): static
    {
        return $this->state([
            'scope_type' => PlanScopeType::User,
            'scope_user_id' => $userId,
            'scope_pipeline_id' => null,
            'scope_product_group_id' => null,
            'scope_key' => 'user:'.$userId,
        ]);
    }

    public function forPipeline(int $pipelineId): static
    {
        return $this->state([
            'scope_type' => PlanScopeType::Pipeline,
            'scope_user_id' => null,
            'scope_pipeline_id' => $pipelineId,
            'scope_product_group_id' => null,
            'scope_key' => 'pipeline:'.$pipelineId,
        ]);
    }

    public function forCompany(): static
    {
        return $this->state([
            'scope_type' => PlanScopeType::Company,
            'scope_user_id' => null,
            'scope_pipeline_id' => null,
            'scope_key' => 'company',
        ]);
    }

    public function forPeriod(int $year, ?int $month): static
    {
        return $this->state([
            'period_year' => $year,
            'period_month' => $month,
            'period_month_key' => $month ?? 0,
        ]);
    }

    public function annual(): static
    {
        return $this->state([
            'layer' => PlanLayer::Annual,
        ]);
    }

    /**
     * R4 task-matrix plan cell — count metric, config carries the activity
     * kind being planned (contract §3.5). Clears the money columns.
     */
    public function tasksCompleted(string $activityKind, int $count): static
    {
        return $this->state([
            'metric' => PlanMetric::TasksCompleted,
            'value_kopecks' => null,
            'currency' => null,
            'value_count' => $count,
            'config' => ['activity_kind' => $activityKind],
        ]);
    }

    /**
     * R5 conversion plan cell — the plan value is a TARGET PERCENT (contract
     * §3.5/§O4), config carries the numerator/denominator pair definition.
     *
     * @param  array<string, mixed>  $numerator
     * @param  array<string, mixed>  $denominator
     */
    public function conversion(array $numerator, array $denominator, int $targetPct): static
    {
        return $this->state([
            'metric' => PlanMetric::Conversion,
            'value_kopecks' => null,
            'currency' => null,
            'value_count' => $targetPct,
            'config' => ['numerator' => $numerator, 'denominator' => $denominator, 'unit' => 'pct'],
        ]);
    }

    /**
     * R6 product-income plan cell — money metric, always
     * scope_type=company + scope_product_group_id set (contract §3.3, always
     * company-wide per line, never per-manager for this sprint).
     */
    public function productIncome(int $productGroupId, int $kopecks, string $currency = 'RUB'): static
    {
        return $this->state([
            'metric' => PlanMetric::ProductIncome,
            'scope_type' => PlanScopeType::Company,
            'scope_user_id' => null,
            'scope_pipeline_id' => null,
            'scope_product_group_id' => $productGroupId,
            'scope_key' => 'pg:'.$productGroupId,
            'value_kopecks' => $kopecks,
            'value_count' => null,
            'currency' => $currency,
            'config' => null,
        ]);
    }
}
