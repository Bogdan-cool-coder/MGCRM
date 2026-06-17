<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Http\Requests\Sales\DashboardRequest;
use Illuminate\Support\Carbon;

/**
 * Immutable value object for the Sales Dashboard query filters.
 *
 * Period named-enum (Q4 decision): current_month|last_month|current_quarter|current_year
 * is converted to Carbon date_from/date_to at construction time so all aggregators
 * receive plain date boundaries and never touch the raw period string again.
 *
 * prevPeriod() returns a shifted copy for trend_pct comparison. The shift mirrors
 * the length of the current period (month → previous month, quarter → previous quarter).
 */
readonly class DashboardFilters
{
    public function __construct(
        public string $period,
        public Carbon $dateFrom,
        public Carbon $dateTo,
        public ?int $pipelineId,
        public ?int $managerId,
    ) {}

    public static function fromRequest(DashboardRequest $request): self
    {
        $period = $request->string('period', 'current_month')->toString();
        [$from, $to] = self::resolveDateRange($period);

        return new self(
            period: $period,
            dateFrom: $from,
            dateTo: $to,
            pipelineId: $request->filled('pipeline_id') ? (int) $request->input('pipeline_id') : null,
            managerId: $request->filled('manager_id') ? (int) $request->input('manager_id') : null,
        );
    }

    /**
     * Return a copy shifted back by one period for trend comparison.
     */
    public function prevPeriod(): self
    {
        [$from, $to] = match ($this->period) {
            'current_month' => [
                now()->startOfMonth()->subMonth(),
                now()->startOfMonth()->subMonth()->endOfMonth(),
            ],
            'last_month' => [
                now()->startOfMonth()->subMonths(2),
                now()->startOfMonth()->subMonths(2)->endOfMonth(),
            ],
            'current_quarter' => [
                now()->startOfQuarter()->subQuarter(),
                now()->startOfQuarter()->subQuarter()->endOfQuarter(),
            ],
            'current_year' => [
                now()->startOfYear()->subYear(),
                now()->startOfYear()->subYear()->endOfYear(),
            ],
            default => [
                now()->startOfMonth()->subMonth(),
                now()->startOfMonth()->subMonth()->endOfMonth(),
            ],
        };

        return new self(
            period: 'prev_'.$this->period,
            dateFrom: $from,
            dateTo: $to,
            pipelineId: $this->pipelineId,
            managerId: $this->managerId,
        );
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private static function resolveDateRange(string $period): array
    {
        return match ($period) {
            'last_month' => [
                now()->startOfMonth()->subMonth(),
                now()->startOfMonth()->subMonth()->endOfMonth(),
            ],
            'current_quarter' => [
                now()->startOfQuarter(),
                now()->endOfQuarter(),
            ],
            'current_year' => [
                now()->startOfYear(),
                now()->endOfYear(),
            ],
            default => [ // current_month (and fallback)
                now()->startOfMonth(),
                now()->endOfMonth(),
            ],
        };
    }
}
