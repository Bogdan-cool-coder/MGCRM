<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * MotivationCardItem.params.kpi_type — flexible KPI indicator shape (kind=kpi
 * rows only, contract §9 ОВ-5).
 *   count  — plan_count/fact_count (e.g. FTM meetings).
 *   amount — money-denominated indicator (plan/fact in the row's *_kopecks columns).
 *   manual — a manual done/not-done toggle (params.manual_done bool), no % score.
 */
enum KpiType: string
{
    case Count = 'count';
    case Amount = 'amount';
    case Manual = 'manual';
}
