<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * DealTotalsDTO — aggregated deal financials for a Company.
 *
 * Amounts are ALWAYS integer kopecks (ARCHITECTURE.md §3 — never float).
 * per_currency: ['KZT' => 1_500_000, 'USD' => 50_000]
 * base_total: converted sum in base currency (kopecks), null if any rate missing.
 * open_count: number of non-closed deals.
 * as_of_date: ISO 8601 datetime of when the aggregate was computed.
 */
readonly class DealTotalsDTO
{
    /**
     * @param  array<string, int>  $per_currency  Sub-totals per currency (kopecks)
     * @param  int|null  $base_total  Sum converted to base currency (kopecks); null if rate unavailable
     * @param  string  $base_currency  ISO 4217 base currency code
     * @param  int  $open_count  Number of open (non-closed) deals
     * @param  string  $as_of_date  ISO 8601 timestamp
     */
    public function __construct(
        public array $per_currency,
        public ?int $base_total,
        public string $base_currency,
        public int $open_count,
        public string $as_of_date,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'per_currency' => $this->per_currency,
            'base_total' => $this->base_total,
            'base_currency' => $this->base_currency,
            'open_count' => $this->open_count,
            'as_of_date' => $this->as_of_date,
        ];
    }
}
