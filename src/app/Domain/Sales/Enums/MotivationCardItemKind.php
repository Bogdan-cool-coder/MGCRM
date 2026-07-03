<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * MotivationCardItem row kind. `kpi` rows may repeat (flexible KPI list);
 * base_salary / commission / team_kpi are expected once per card but not
 * DB-enforced (service guards).
 */
enum MotivationCardItemKind: string
{
    case BaseSalary = 'base_salary';
    case Commission = 'commission';
    case Kpi = 'kpi';
    case Bonus = 'bonus';
    case TeamKpi = 'team_kpi';
}
