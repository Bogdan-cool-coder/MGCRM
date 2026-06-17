<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\Catalog\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        return ProductResource::collection(
            $this->service->list($request->query(), (int) $request->query('per_page', 25))
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated());

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product): JsonResource
    {
        $this->authorize('view', $product);

        return ProductResource::make($product->load(['group', 'plans.prices', 'prices']));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResource
    {
        return ProductResource::make(
            $this->service->update($product, $request->validated())
        );
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->service->delete($product);

        return response()->json(null, 204);
    }
}
