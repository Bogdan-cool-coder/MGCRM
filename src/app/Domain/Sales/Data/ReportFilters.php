<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use Illuminate\Support\Carbon;

/**
 * Immutable filter set shared by the Ф2 report aggregators (R1 Registry+Squeeze,
 * R2 Income Schedule; contract §6.4/§6.5). Mirrors KpiFilters/DashboardFilters —
 * the request resolves year/month into Carbon month boundaries once, so every
 * aggregator query works off plain dates instead of re-deriving them.
 */
readonly class ReportFilters
{
    public function __construct(
        public int $year,
        public int $month,
        public Carbon $monthStart,
        public Carbon $monthEnd,
        public ?int $pipelineId = null,
        public ?int $userId = null,
        public ?int $productGroupId = null,
    ) {}

    public static function forMonth(
        int $year,
        int $month,
        ?int $pipelineId = null,
        ?int $userId = null,
        ?int $productGroupId = null,
    ): self {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return new self(
            year: $year,
            month: $month,
            monthStart: $start,
            monthEnd: $start->copy()->endOfMonth()->endOfDay(),
            pipelineId: $pipelineId,
            userId: $userId,
            productGroupId: $productGroupId,
        );
    }

    /**
     * Human-readable Russian month label, e.g. "Январь 2026" — same table as
     * KpiFilters::monthLabel() so R1/R2 meta.period.label matches the rest of
     * the Sales Analytics surface verbatim.
     */
    public function monthLabel(): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март',
            4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь',
            10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return ($months[$this->month] ?? $this->monthStart->format('F')).' '.$this->year;
    }
}
