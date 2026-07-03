<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * PlanScopeType — which axis a plan_targets cell targets (contract §3.2).
 * Department is NOT a scope level in this sprint (contract §1.3 anchor
 * decision) — the funnel axis is `pipeline_id`, not department.
 */
enum PlanScopeType: string
{
    case User = 'user'; // per-manager (scope_user_id set)
    case Pipeline = 'pipeline'; // per-funnel Global / Global AI (scope_pipeline_id set)
    case Company = 'company'; // company-wide (both scope FKs null; product_group may still be set)

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
