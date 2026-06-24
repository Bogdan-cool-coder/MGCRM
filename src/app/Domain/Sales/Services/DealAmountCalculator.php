<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

/**
 * DealAmountCalculator — the single source of truth for folding the deal-level
 * `discount_percent` into a set of (already per-line-discounted) line amounts.
 *
 * The canonical `deals.amount` is NET: it equals the sum of the per-line amounts
 * after the deal-level discount_percent has been applied uniformly. The percent
 * is applied PER LINE, then the lines are summed, so the grand total always
 * equals the sum of the discounted prices the user sees on each row (no penny
 * drift between the line list and the total). All arithmetic is integer kopecks;
 * `round()` is the same half-away-from-zero PHP rounding used everywhere else.
 *
 * This pure calculator has no dependencies and is unit-testable without the DB.
 * Both DealService::recalcAmount() (persists deals.amount) and
 * DealResource::discountedTotals() (display-only products_*_total) call it so the
 * formula never diverges between the stored value and the rendered one.
 */
final class DealAmountCalculator
{
    /** The deal-level discount percent ceiling (business rule: max 50%). */
    public const MAX_DISCOUNT_PERCENT = 50;

    /**
     * Net deal amount (kopecks) = Σ round(lineAmount * (100 - pct) / 100), where
     * pct is clamped into [0, MAX_DISCOUNT_PERCENT]. pct = 0 → equals the gross
     * line sum unchanged. Negative line amounts are not expected (line netAmount
     * clamps to >= 0) but the formula is safe for them.
     *
     * @param  iterable<int>  $lineAmounts  per-line net amounts (post per-line discount), kopecks
     */
    public function netFromLines(iterable $lineAmounts, int $discountPercent): int
    {
        $pct = $this->clampPercent($discountPercent);

        $net = 0;
        foreach ($lineAmounts as $lineGross) {
            $net += $this->applyPercent((int) $lineGross, $pct);
        }

        return $net;
    }

    /**
     * Apply the deal-level percent to a single line amount (kopecks), with the
     * canonical per-line integer rounding.
     */
    public function applyPercent(int $lineAmount, int $discountPercent): int
    {
        $pct = $this->clampPercent($discountPercent);

        return (int) round($lineAmount * (100 - $pct) / 100);
    }

    /**
     * Clamp an incoming deal-level discount percent into [0, MAX_DISCOUNT_PERCENT].
     * A value above the ceiling is clamped to the ceiling (50), never rejected
     * (business rule). null → 0 (no discount). Non-numeric input coerces to 0.
     */
    public function clampPercent(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        $percent = (int) $value;

        return max(0, min(self::MAX_DISCOUNT_PERCENT, $percent));
    }
}
