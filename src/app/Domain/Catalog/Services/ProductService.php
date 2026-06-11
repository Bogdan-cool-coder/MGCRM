<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProductService — all business logic for catalog products, plans and prices.
 */
class ProductService
{
    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Product::query()
            ->with(['group', 'plans', 'prices'])
            ->when(! empty($filters['active_only']), fn (Builder $q) => $q->where('is_active', true))
            ->when(! empty($filters['group_id']), fn (Builder $q) => $q->where('group_id', $filters['group_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $product = Product::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'group_id' => $data['group_id'] ?? null,
                'pricing_type' => $data['pricing_type'] ?? 'fixed',
                'maps_to_product_code' => $data['maps_to_product_code'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            if (! empty($data['plans'])) {
                foreach ($data['plans'] as $planData) {
                    $product->plans()->create($planData);
                }
            }

            if (! empty($data['prices'])) {
                foreach ($data['prices'] as $priceData) {
                    $product->prices()->create($priceData);
                }
            }

            return $product->load(['group', 'plans', 'prices']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        $product->refresh();

        return $product->load(['group', 'plans', 'prices']);
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            // Guard: if DealProduct rows reference this product, return 409.
            // The deal_products table belongs to S1.3; we check it only if it exists.
            if (DB::getSchemaBuilder()->hasTable('deal_products')) {
                $hasDeals = DB::table('deal_products')
                    ->where('product_id', $product->id)
                    ->exists();

                if ($hasDeals) {
                    abort(409, 'Cannot delete product with existing deal line-items.');
                }
            }

            $product->delete();
        });
    }

    // ---- Plans ----

    /** @param array<string, mixed> $data */
    public function createPlan(Product $product, array $data): ProductPlan
    {
        return $product->plans()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function updatePlan(ProductPlan $plan, array $data): ProductPlan
    {
        $plan->update($data);
        $plan->refresh();

        return $plan;
    }

    public function deletePlan(ProductPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            if (DB::getSchemaBuilder()->hasTable('deal_products')) {
                $hasDeals = DB::table('deal_products')
                    ->where('plan_id', $plan->id)
                    ->exists();

                if ($hasDeals) {
                    abort(409, 'Cannot delete plan with existing deal line-items.');
                }
            }

            $plan->delete();
        });
    }

    // ---- Prices ----

    /**
     * Batch upsert prices for a product.
     * Input: [{plan_id, currency_code, amount}, ...].
     * Uses updateOrCreate to guarantee idempotency.
     *
     * @param  array<int, array<string, mixed>>  $prices
     * @return Collection<int, ProductPrice>
     */
    public function upsertPrices(Product $product, array $prices): Collection
    {
        return DB::transaction(function () use ($product, $prices): Collection {
            foreach ($prices as $priceData) {
                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'plan_id' => $priceData['plan_id'] ?? null,
                        'currency_code' => $priceData['currency_code'],
                    ],
                    [
                        'amount' => (int) $priceData['amount'],
                        'valid_from' => $priceData['valid_from'] ?? null,
                        'valid_to' => $priceData['valid_to'] ?? null,
                    ],
                );
            }

            return $product->prices()->get();
        });
    }

    public function deletePrice(ProductPrice $price): void
    {
        $price->delete();
    }

    /**
     * Returns a price snapshot (integer kopecks) for use in DealProduct.
     * Returns null if no price exists for the given combination.
     */
    public function getPriceSnapshot(int $productId, ?int $planId, string $currencyCode): ?int
    {
        $price = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('plan_id', $planId)
            ->where('currency_code', $currencyCode)
            ->first();

        return $price ? (int) $price->amount : null;
    }

    /**
     * Alias: get price for currency (used by DealProduct in S1.3).
     */
    public function getPriceForCurrency(int $productId, ?int $planId, string $currencyCode): ?int
    {
        return $this->getPriceSnapshot($productId, $planId, $currencyCode);
    }
}
