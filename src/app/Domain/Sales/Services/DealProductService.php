<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DealProductService — line-item CRUD. unit_price is a snapshot taken from the
 * Catalog (ProductService::getPriceSnapshot, kopecks) at add time, with an
 * optional manual override. discount is a manual per-line reduction (kopecks):
 * amount = max(0, round(quantity * unit_price) - discount). Every mutation
 * re-derives Deal.amount.
 */
class DealProductService
{
    public function __construct(
        private readonly ProductService $products,
        private readonly DealService $deals,
    ) {}

    /**
     * @return Collection<int, DealProduct>
     */
    public function list(Deal $deal): Collection
    {
        return DealProduct::query()
            ->where('deal_id', $deal->id)
            ->with(['product:id,code,name', 'plan:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Add a line item. Price = override (if given) else Catalog snapshot in the
     * deal's currency. Recomputes Deal.amount.
     *
     * Perpetual awareness (N4): when no explicit plan_id is given AND the deal is
     * flagged perpetual_license=true, the new line is created on the product's
     * perpetual plan (getPerpetualPlan) so it lands on the perpetual price right
     * away — keeping a new line consistent with the rest of an already-perpetual
     * deal. An explicit plan_id in $data always wins (manual override). If the
     * product has no perpetual plan the resolution falls through to the base
     * price (plan_id = null), exactly as a non-perpetual add would.
     *
     * @param  array<string, mixed>  $data
     */
    public function addProduct(Deal $deal, array $data): DealProduct
    {
        return DB::transaction(function () use ($deal, $data): DealProduct {
            $currency = $data['currency'] ?? $deal->currency;
            $productId = (int) $data['product_id'];

            // Resolve the plan: an explicit plan_id wins; otherwise, on a perpetual
            // deal, prefer the product's perpetual plan; else the base price (null).
            if (array_key_exists('plan_id', $data) && $data['plan_id'] !== null) {
                $planId = (int) $data['plan_id'];
            } elseif ($deal->perpetual_license === true) {
                $planId = $this->products->getPerpetualPlan($productId)?->id;
            } else {
                $planId = null;
            }

            $unitPrice = isset($data['unit_price'])
                ? (int) $data['unit_price']
                : $this->products->getPriceSnapshot($productId, $planId, $currency);

            if ($unitPrice === null) {
                throw ValidationException::withMessages([
                    'unit_price' => 'No catalog price found for this product/plan in '.$currency.'. Provide unit_price.',
                ]);
            }

            $quantity = (float) ($data['quantity'] ?? 1);
            $discount = isset($data['discount']) ? (int) $data['discount'] : 0;
            $amount = $this->netAmount($quantity, $unitPrice, $discount);

            $product = DealProduct::create([
                'deal_id' => $deal->id,
                'product_id' => $productId,
                'plan_id' => $planId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'currency' => $currency,
                'amount' => $amount,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $this->deals->recalcAmount($deal);

            return $product->load(['product:id,code,name', 'plan:id,name']);
        });
    }

    /**
     * Update a line item (quantity/unit_price/discount/sort_order). Recomputes
     * amount and Deal.amount.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(DealProduct $dealProduct, array $data): DealProduct
    {
        return DB::transaction(function () use ($dealProduct, $data): DealProduct {
            if (array_key_exists('quantity', $data)) {
                $dealProduct->quantity = (float) $data['quantity'];
            }
            if (array_key_exists('unit_price', $data) && $data['unit_price'] !== null) {
                $dealProduct->unit_price = (int) $data['unit_price'];
            }
            if (array_key_exists('discount', $data) && $data['discount'] !== null) {
                $dealProduct->discount = (int) $data['discount'];
            }
            if (array_key_exists('sort_order', $data)) {
                $dealProduct->sort_order = (int) $data['sort_order'];
            }

            $dealProduct->amount = $this->netAmount(
                (float) $dealProduct->quantity,
                (int) $dealProduct->unit_price,
                (int) $dealProduct->discount,
            );
            $dealProduct->save();

            $deal = $dealProduct->deal()->first();
            if ($deal !== null) {
                $this->deals->recalcAmount($deal);
            }

            return $dealProduct->load(['product:id,code,name', 'plan:id,name']);
        });
    }

    public function removeProduct(DealProduct $dealProduct): void
    {
        DB::transaction(function () use ($dealProduct): void {
            $deal = $dealProduct->deal()->first();
            $dealProduct->delete();

            if ($deal !== null) {
                $this->deals->recalcAmount($deal);
            }
        });
    }

    /**
     * Re-price every line item of a deal for a license-mode switch (N4, «Вечная
     * лицензия»). Called from DealService::update() when Deal.perpetual_license
     * flips. For each line:
     *   - plan = $perpetual ? product's perpetual plan : the line's base plan
     *     (plan_id = null — the subscription/base price). When switching OFF we
     *     deliberately reset to the base price rather than guessing a prior
     *     subscription plan: the schema keeps no memory of the pre-perpetual plan,
     *     and the base price (plan_id = null) is the same default a plain add uses.
     *   - unit_price = getPriceSnapshot(product, plan?->id, deal.currency); amount
     *     re-derived from the snapshot, KEEPING the line's manual discount/quantity
     *     (same netAmount() formula as add/update — a discount survives a re-price).
     *
     * Boundary case — product with no perpetual plan while $perpetual = true, OR a
     * currency with no perpetual/base price: there is no snapshot to apply, so the
     * line is LEFT UNTOUCHED (its previous unit_price/plan_id stand) instead of
     * being zeroed or throwing. This never aborts the bulk re-price: the toggle is
     * a deal-wide convenience and a single unpriced product must not block it. We
     * do NOT fall back to a manual override here — applyLicenseMode only moves
     * lines that have a real catalog price for the target mode. (No silent 422 like
     * addProduct: a per-line catalog gap during a bulk re-price is expected and
     * tolerated, whereas addProduct fails because the caller asked for that one
     * specific line.)
     *
     * Whole operation is one transaction; a single recalcAmount at the end re-
     * derives Deal.amount — and it self-respects amount_locked (a locked budget is
     * left as-is even though the line sums changed; N3 × N4 interaction).
     */
    public function applyLicenseMode(Deal $deal, bool $perpetual): void
    {
        DB::transaction(function () use ($deal, $perpetual): void {
            $lines = DealProduct::query()
                ->where('deal_id', $deal->id)
                ->get();

            foreach ($lines as $line) {
                $productId = (int) $line->product_id;

                $planId = $perpetual
                    ? $this->products->getPerpetualPlan($productId)?->id
                    : null;

                $unitPrice = $this->products->getPriceSnapshot(
                    $productId,
                    $planId,
                    (string) $line->currency,
                );

                // No catalog price for the target mode/currency: leave this line
                // exactly as it was (do not zero it, do not throw) — see docblock.
                if ($unitPrice === null) {
                    continue;
                }

                $line->plan_id = $planId;
                $line->unit_price = $unitPrice;
                $line->amount = $this->netAmount(
                    (float) $line->quantity,
                    $unitPrice,
                    (int) $line->discount,
                );
                $line->save();
            }

            $this->deals->recalcAmount($deal);
        });
    }

    /**
     * Net line-item total in kopecks: gross (round(quantity * unit_price)) minus
     * the manual discount, clamped to >= 0 so a discount never produces a
     * negative line. Single source of truth for both add and update.
     */
    private function netAmount(float $quantity, int $unitPrice, int $discount): int
    {
        $gross = (int) round($quantity * $unitPrice);

        return max(0, $gross - $discount);
    }
}
