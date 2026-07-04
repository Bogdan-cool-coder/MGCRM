<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Http\Requests\Sales\DashboardRequest;
use Illuminate\Support\Carbon;

/**
 * Immutable value object for the Sales Dashboard query filters.
 *
 * Two mutually-exclusive selection modes (Ф8 months[] extension):
 *
 * - Legacy named-enum `period` (current_month|last_month|current_quarter|current_year)
 *   is converted to Carbon date_from/date_to at construction time, exactly as before.
 * - `months[]` — an explicit list of 1..12 "YYYY-MM" strings. When present it takes
 *   priority over `period` (back-compat: existing period-only callers are unaffected
 *   because they never send `months`).
 *
 * Both modes ultimately resolve to `monthRanges` — a list of [start, end] Carbon
 * pairs, one per calendar month in the selection. `dateFrom`/`dateTo` remain the
 * envelope (min start / max end) for meta.period and the xlsx export label; the
 * actual row filter in SalesDashboardService walks `monthRanges` so a
 * non-contiguous months[] selection (e.g. Jan + Mar) does NOT pull in the Feb
 * rows a plain min/max range would include.
 *
 * prevPeriod() returns a shifted copy for trend_pct comparison:
 * - single month (period-mode or months=[one]) → previous calendar month, as before.
 * - contiguous months[] (N consecutive months) → the N months immediately before
 *   the earliest selected month.
 * - non-contiguous months[] → no honest single "previous period" exists, so
 *   prevPeriod() returns null and callers must skip the trend (report it as null).
 */
readonly class DashboardFilters
{
    /**
     * $monthRanges defaults to `[]` so existing direct-construction call sites
     * (unit tests building an ad-hoc dateFrom/dateTo window that isn't aligned
     * to calendar-month boundaries) keep compiling unchanged. ranges() below
     * is what every query actually reads, and falls back to the plain
     * [dateFrom, dateTo] envelope when monthRanges is empty.
     *
     * @param  list<array{Carbon, Carbon}>  $monthRanges
     */
    public function __construct(
        public string $period,
        public Carbon $dateFrom,
        public Carbon $dateTo,
        public ?int $pipelineId,
        public ?int $managerId,
        public array $monthRanges = [],
        public bool $isMultiMonth = false,
    ) {}

    /**
     * The ranges every aggregator query filters on. Falls back to the plain
     * [dateFrom, dateTo] envelope when monthRanges was left empty (legacy
     * direct construction outside fromRequest()/fromMonths()).
     *
     * @return list<array{Carbon, Carbon}>
     */
    public function ranges(): array
    {
        return $this->monthRanges !== [] ? $this->monthRanges : [[$this->dateFrom, $this->dateTo]];
    }

    public static function fromRequest(DashboardRequest $request): self
    {
        $months = $request->input('months');

        if (is_array($months) && $months !== []) {
            return self::fromMonths($months, $request);
        }

        $period = $request->string('period', 'current_month')->toString();
        [$from, $to] = self::resolveDateRange($period);

        return new self(
            period: $period,
            dateFrom: $from,
            dateTo: $to,
            pipelineId: $request->filled('pipeline_id') ? (int) $request->input('pipeline_id') : null,
            managerId: $request->filled('manager_id') ? (int) $request->input('manager_id') : null,
            monthRanges: [[$from, $to]],
            isMultiMonth: false,
        );
    }

    /**
     * @param  list<string>  $months  validated "YYYY-MM" strings
     */
    private static function fromMonths(array $months, DashboardRequest $request): self
    {
        $sorted = collect($months)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $monthRanges = array_map(
            static function (string $ym): array {
                $start = Carbon::createFromFormat('Y-m', $ym)->startOfMonth()->startOfDay();

                return [$start, $start->copy()->endOfMonth()->endOfDay()];
            },
            $sorted,
        );

        $dateFrom = $monthRanges[0][0];
        $dateTo = $monthRanges[array_key_last($monthRanges)][1];

        return new self(
            period: 'months:'.implode(',', $sorted),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            pipelineId: $request->filled('pipeline_id') ? (int) $request->input('pipeline_id') : null,
            managerId: $request->filled('manager_id') ? (int) $request->input('manager_id') : null,
            monthRanges: $monthRanges,
            isMultiMonth: count($sorted) > 1,
        );
    }

    /**
     * Whether the selected months[] form an unbroken run of consecutive
     * calendar months (e.g. 2026-04, 2026-05, 2026-06). A single month is
     * trivially contiguous. Only meaningful when isMultiMonth semantics apply
     * (months[] mode) — legacy period-mode ranges are always contiguous by
     * construction.
     */
    public function isContiguous(): bool
    {
        $n = count($this->monthRanges);

        if ($n <= 1) {
            return true;
        }

        for ($i = 1; $i < $n; $i++) {
            $expectedStart = $this->monthRanges[$i - 1][0]->copy()->addMonthNoOverflow();

            if (! $expectedStart->isSameMonth($this->monthRanges[$i][0])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return a copy shifted back for trend comparison, or null when no honest
     * "previous period" can be derived (non-contiguous months[] selection).
     */
    public function prevPeriod(): ?self
    {
        if ($this->monthRanges !== [] && str_starts_with($this->period, 'months:')) {
            return $this->prevPeriodForMonths();
        }

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
            monthRanges: [[$from, $to]],
            isMultiMonth: false,
        );
    }

    /**
     * months[] trend shift: non-contiguous selections have no single honest
     * "previous period" (skip N-1, month, N+1 ambiguity), so callers must treat
     * a null return as "no trend available". Contiguous selections (including
     * the single-month case) shift back by the same number of months, ending
     * right before the earliest selected month.
     */
    private function prevPeriodForMonths(): ?self
    {
        if (! $this->isContiguous()) {
            return null;
        }

        $n = count($this->monthRanges);
        $earliestStart = $this->monthRanges[0][0];

        $monthRanges = [];

        for ($i = $n; $i >= 1; $i--) {
            $start = $earliestStart->copy()->subMonthsNoOverflow($i)->startOfMonth()->startOfDay();
            $monthRanges[] = [$start, $start->copy()->endOfMonth()->endOfDay()];
        }

        $dateFrom = $monthRanges[0][0];
        $dateTo = $monthRanges[array_key_last($monthRanges)][1];

        return new self(
            period: 'prev_'.$this->period,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            pipelineId: $this->pipelineId,
            managerId: $this->managerId,
            monthRanges: $monthRanges,
            isMultiMonth: $n > 1,
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
