<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Services\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UpsertProductPricesRequest;
use App\Http\Resources\Catalog\ProductPriceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductPriceController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
    ) {}

    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        $prices = $product->prices()
            ->when($request->query('currency_code'), fn ($q) => $q->where('currency_code', $request->query('currency_code')))
            ->get();

        return ProductPriceResource::collection($prices);
    }

    public function store(UpsertProductPricesRequest $request, Product $product): AnonymousResourceCollection
    {
        $prices = $this->service->upsertPrices($product, $request->validated('prices'));

        return ProductPriceResource::collection($prices);
    }

    public function destroy(Request $request, Product $product, ProductPrice $price): JsonResponse
    {
        $this->authorize('update', $product);

        $this->service->deletePrice($price);

        return response()->json(null, 204);
    }
}
