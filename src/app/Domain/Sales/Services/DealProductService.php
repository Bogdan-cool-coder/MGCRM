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
     * @param  array<string, mixed>  $data
     */
    public function addProduct(Deal $deal, array $data): DealProduct
    {
        return DB::transaction(function () use ($deal, $data): DealProduct {
            $currency = $data['currency'] ?? $deal->currency;

            $unitPrice = isset($data['unit_price'])
                ? (int) $data['unit_price']
                : $this->products->getPriceSnapshot(
                    (int) $data['product_id'],
                    isset($data['plan_id']) ? (int) $data['plan_id'] : null,
                    $currency,
                );

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
                'product_id' => (int) $data['product_id'],
                'plan_id' => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
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
