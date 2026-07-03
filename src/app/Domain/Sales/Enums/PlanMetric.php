<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * PlanMetric — which plannable indicator a plan_targets cell targets
 * (contract §3.1). Ф1 builds new_income end-to-end; the others validate but
 * their fact-helpers are stubbed until Ф2+.
 */
enum PlanMetric: string
{
    case NewIncome = 'new_income'; // money — SpaceCRM «Новые поступления»
    case ExpectedDeals = 'expected_deals'; // count — number of deals expected to close/pay
    case TasksCompleted = 'tasks_completed'; // count — completed activities of a given kind
    case Conversion = 'conversion'; // count/pct — a конверсия pair target
    case ProductIncome = 'product_income'; // money — income plan per product line

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** money metrics carry value_kopecks + currency. */
    public function isMoney(): bool
    {
        return in_array($this, [self::NewIncome, self::ProductIncome], true);
    }

    /** count metrics carry value_count. */
    public function isCount(): bool
    {
        return ! $this->isMoney();
    }

    /**
     * Scope/metric compatibility matrix (contract §3.3) — the single source of
     * truth checked by both FormRequest::withValidator() and (defensively) the
     * Service. Any combination not listed here → 422.
     */
    public function isCompatibleWithScope(PlanScopeType $scopeType): bool
    {
        return match ($this) {
            self::NewIncome, self::ExpectedDeals => in_array($scopeType, [PlanScopeType::User, PlanScopeType::Pipeline, PlanScopeType::Company], true),
            self::TasksCompleted, self::Conversion => in_array($scopeType, [PlanScopeType::User, PlanScopeType::Pipeline], true),
            self::ProductIncome => $scopeType === PlanScopeType::Company,
        };
    }
}
