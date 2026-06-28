<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Services\DealProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDealProductRequest;
use App\Http\Requests\Sales\UpdateDealProductRequest;
use App\Http\Resources\Sales\DealProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin nested line-item controller. Every mutation re-derives Deal.amount in the
 * service. Line items not belonging to the route deal → 404.
 */
class DealProductController extends Controller
{
    public function __construct(
        private readonly DealProductService $service,
    ) {}

    public function index(Request $request, Deal $deal): AnonymousResourceCollection
    {
        $this->authorize('view', $deal);

        return DealProductResource::collection($this->service->list($deal));
    }

    public function store(StoreDealProductRequest $request, Deal $deal): JsonResource
    {
        $product = $this->service->addProduct(
            $deal,
            $request->validated(),
            $this->allowPriceOverride($request, $deal),
        );

        return DealProductResource::make($product);
    }

    public function update(UpdateDealProductRequest $request, Deal $deal, DealProduct $dealProduct): JsonResource
    {
        $this->assertBelongsToDeal($deal, $dealProduct);

        $updated = $this->service->updateProduct(
            $dealProduct,
            $request->validated(),
            $this->allowPriceOverride($request, $deal),
        );

        return DealProductResource::make($updated);
    }

    public function destroy(Request $request, Deal $deal, DealProduct $dealProduct): Response
    {
        $this->authorize('update', $deal);
        $this->assertBelongsToDeal($deal, $dealProduct);

        $this->service->removeProduct($dealProduct);

        return response()->noContent();
    }

    /**
     * A manual unit_price is honoured by the service only when the client opts in
     * with override_price=true AND the user is authorized to override the catalog
     * price (DealPolicy::overridePrice — managerial scope). Otherwise the service
     * snapshots the catalog price and ignores any supplied unit_price (#3 — price
     * tampering). Authorization is silently downgraded to "no override" rather than
     * thrown: a tampered price falls back to the trustworthy catalog value.
     */
    private function allowPriceOverride(Request $request, Deal $deal): bool
    {
        return $request->boolean('override_price')
            && $request->user()->can('overridePrice', $deal);
    }

    private function assertBelongsToDeal(Deal $deal, DealProduct $dealProduct): void
    {
        abort_unless((int) $dealProduct->deal_id === (int) $deal->id, 404);
    }
}
