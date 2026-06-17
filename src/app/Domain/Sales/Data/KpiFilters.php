<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Http\Requests\Sales\KpiRequest;
use Illuminate\Support\Carbon;

/**
 * Immutable value object for KPI / manager-cabinet query filters (S1.8).
 *
 * Period resolution mirrors DashboardFilters: named enum or YYYY-MM string
 * is converted to Carbon date boundaries at construction time.
 * Supported named values: current_month | last_month | current_quarter | current_year.
 * YYYY-MM (e.g. "2026-05") is also accepted — resolved to first/last day of that month.
 *
 * prevPeriod() shifts back one period for trend computation.
 */
readonly class KpiFilters
{
    public function __construct(
        public string $period,
        public Carbon $dateFrom,
        public Carbon $dateTo,
        public ?int $userId,
    ) {}

    public static function fromRequest(KpiRequest $request): self
    {
        $period = $request->string('period', 'current_month')->toString();
        [$from, $to] = self::resolveDateRange($period);

        return new self(
            period: $period,
            dateFrom: $from,
            dateTo: $to,
            userId: $request->filled('user_id') ? (int) $request->input('user_id') : null,
        );
    }

    /**
     * Build a KpiFilters for a concrete year+month without a request (seeder / tests).
     */
    public static function forMonth(int $year, int $month, ?int $userId = null): self
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth()->endOfDay();
        $period = sprintf('%04d-%02d', $year, $month);

        return new self(
            period: $period,
            dateFrom: $from,
            dateTo: $to,
            userId: $userId,
        );
    }

    /**
     * Human-readable month label in Russian, e.g. "Июнь 2026".
     */
    public function monthLabel(): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март',
            4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь',
            10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        $month = (int) $this->dateFrom->month;
        $year = (int) $this->dateFrom->year;

        return ($months[$month] ?? $this->dateFrom->format('F')).' '.$year;
    }

    /**
     * Shift back one period for trend comparison.
     */
    public function prevPeriod(): self
    {
        [$from, $to] = match (true) {
            $this->period === 'current_month' => [
                now()->startOfMonth()->subMonth()->startOfDay(),
                now()->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
            ],
            $this->period === 'last_month' => [
                now()->startOfMonth()->subMonths(2)->startOfDay(),
                now()->startOfMonth()->subMonths(2)->endOfMonth()->endOfDay(),
            ],
            $this->period === 'current_quarter' => [
                now()->startOfQuarter()->subQuarter()->startOfDay(),
                now()->startOfQuarter()->subQuarter()->endOfQuarter()->endOfDay(),
            ],
            $this->period === 'current_year' => [
                now()->startOfYear()->subYear()->startOfDay(),
                now()->startOfYear()->subYear()->endOfYear()->endOfDay(),
            ],
            default => [
                $this->dateFrom->copy()->subMonth()->startOfMonth()->startOfDay(),
                $this->dateFrom->copy()->subMonth()->endOfMonth()->endOfDay(),
            ],
        };

        return new self(
            period: 'prev_'.$this->period,
            dateFrom: $from,
            dateTo: $to,
            userId: $this->userId,
        );
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private static function resolveDateRange(string $period): array
    {
        return match ($period) {
            'last_month' => [
                now()->startOfMonth()->subMonth()->startOfDay(),
                now()->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
            ],
            'current_quarter' => [
                now()->startOfQuarter()->startOfDay(),
                now()->endOfQuarter()->endOfDay(),
            ],
            'current_year' => [
                now()->startOfYear()->startOfDay(),
                now()->endOfYear()->endOfDay(),
            ],
            default => self::resolveYyyyMmOrCurrentMonth($period),
        };
    }

    /**
     * Accept YYYY-MM (e.g. "2026-05") or fall back to current_month.
     *
     * @return array{Carbon, Carbon}
     */
    private static function resolveYyyyMmOrCurrentMonth(string $period): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth()->startOfDay();

            return [$from, $from->copy()->endOfMonth()->endOfDay()];
        }

        // current_month (default) or unknown string
        return [
            now()->startOfMonth()->startOfDay(),
            now()->endOfMonth()->endOfDay(),
        ];
    }
}
