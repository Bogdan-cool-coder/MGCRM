<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Data\DashboardFilters;
use App\Http\Requests\Sales\DashboardRequest;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit tests for the Ф8 months[] extension to DashboardFilters — pure value
 * object construction/derivation, no DB involved.
 */
class DashboardFiltersMonthsTest extends TestCase
{
    private function filtersForMonths(array $months): DashboardFilters
    {
        $request = DashboardRequest::create(
            '/api/sales/dashboard',
            'GET',
            ['months' => $months],
        );

        return DashboardFilters::fromRequest($request);
    }

    // -------------------------------------------------------------------------
    // Single month == period=current_month equivalent
    // -------------------------------------------------------------------------

    public function test_single_month_resolves_to_calendar_month_boundaries(): void
    {
        $filters = $this->filtersForMonths(['2026-05']);

        $this->assertSame('2026-05-01', $filters->dateFrom->toDateString());
        $this->assertSame('2026-05-31', $filters->dateTo->toDateString());
        $this->assertFalse($filters->isMultiMonth);
        $this->assertCount(1, $filters->ranges());
    }

    public function test_single_month_meta_echoes_the_month(): void
    {
        $filters = $this->filtersForMonths(['2026-05']);

        $this->assertSame('months:2026-05', $filters->period);
    }

    // -------------------------------------------------------------------------
    // Multi-month contiguous
    // -------------------------------------------------------------------------

    public function test_contiguous_multi_month_produces_one_range_per_month(): void
    {
        $filters = $this->filtersForMonths(['2026-04', '2026-05', '2026-06']);

        $this->assertTrue($filters->isMultiMonth);
        $this->assertTrue($filters->isContiguous());
        $this->assertCount(3, $filters->ranges());
        $this->assertSame('2026-04-01', $filters->dateFrom->toDateString());
        $this->assertSame('2026-06-30', $filters->dateTo->toDateString());
    }

    public function test_contiguous_multi_month_prev_period_shifts_before_earliest(): void
    {
        // 3 contiguous months (Apr, May, Jun) → prev period = the 3 months
        // immediately before Apr: Jan, Feb, Mar.
        $filters = $this->filtersForMonths(['2026-04', '2026-05', '2026-06']);

        $prev = $filters->prevPeriod();

        $this->assertNotNull($prev);
        $this->assertCount(3, $prev->ranges());
        $this->assertSame('2026-01-01', $prev->dateFrom->toDateString());
        $this->assertSame('2026-03-31', $prev->dateTo->toDateString());
    }

    public function test_single_month_prev_period_is_previous_calendar_month(): void
    {
        $filters = $this->filtersForMonths(['2026-05']);

        $prev = $filters->prevPeriod();

        $this->assertNotNull($prev);
        $this->assertSame('2026-04-01', $prev->dateFrom->toDateString());
        $this->assertSame('2026-04-30', $prev->dateTo->toDateString());
    }

    public function test_months_are_sorted_and_deduplicated_regardless_of_input_order(): void
    {
        $filters = $this->filtersForMonths(['2026-06', '2026-04', '2026-05', '2026-05']);

        $this->assertSame('2026-04-01', $filters->dateFrom->toDateString());
        $this->assertSame('2026-06-30', $filters->dateTo->toDateString());
        $this->assertCount(3, $filters->ranges());
    }

    // -------------------------------------------------------------------------
    // Non-contiguous
    // -------------------------------------------------------------------------

    public function test_non_contiguous_months_is_not_contiguous(): void
    {
        $filters = $this->filtersForMonths(['2026-01', '2026-03']);

        $this->assertFalse($filters->isContiguous());
    }

    public function test_non_contiguous_months_prev_period_is_null(): void
    {
        $filters = $this->filtersForMonths(['2026-01', '2026-03']);

        $this->assertNull($filters->prevPeriod());
    }

    public function test_non_contiguous_months_envelope_spans_min_to_max_but_ranges_exclude_the_gap(): void
    {
        $filters = $this->filtersForMonths(['2026-01', '2026-03']);

        // dateFrom/dateTo is the envelope (used for meta/xlsx label only).
        $this->assertSame('2026-01-01', $filters->dateFrom->toDateString());
        $this->assertSame('2026-03-31', $filters->dateTo->toDateString());

        // ranges() must have exactly 2 entries — Jan and Mar — NOT a single
        // Jan-to-Mar range that would silently include February.
        $ranges = $filters->ranges();
        $this->assertCount(2, $ranges);
        $this->assertSame('2026-01-01', $ranges[0][0]->toDateString());
        $this->assertSame('2026-01-31', $ranges[0][1]->toDateString());
        $this->assertSame('2026-03-01', $ranges[1][0]->toDateString());
        $this->assertSame('2026-03-31', $ranges[1][1]->toDateString());
    }

    // -------------------------------------------------------------------------
    // Legacy direct-construction backward compatibility
    // -------------------------------------------------------------------------

    public function test_legacy_direct_construction_without_month_ranges_falls_back_to_envelope(): void
    {
        $filters = new DashboardFilters(
            period: 'current_month',
            dateFrom: Carbon::parse('2026-05-01'),
            dateTo: Carbon::parse('2026-05-31'),
            pipelineId: null,
            managerId: null,
        );

        $ranges = $filters->ranges();
        $this->assertCount(1, $ranges);
        $this->assertSame('2026-05-01', $ranges[0][0]->toDateString());
        $this->assertSame('2026-05-31', $ranges[0][1]->toDateString());
    }
}
