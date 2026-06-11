<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Services\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductPlanRequest;
use App\Http\Requests\Catalog\UpdateProductPlanRequest;
use App\Http\Resources\Catalog\ProductPlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPlanController extends Controller
{
    public function __construct(
        private readonly ProductService $service,
    ) {}

    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return ProductPlanResource::collection($product->plans()->with('prices')->get());
    }

    public function store(StoreProductPlanRequest $request, Product $product): JsonResponse
    {
        $plan = $this->service->createPlan($product, $request->validated());

        return ProductPlanResource::make($plan)->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product, ProductPlan $plan): JsonResource
    {
        $this->authorize('view', $product);

        return ProductPlanResource::make($plan->load('prices'));
    }

    public function update(UpdateProductPlanRequest $request, Product $product, ProductPlan $plan): JsonResource
    {
        return ProductPlanResource::make($this->service->updatePlan($plan, $request->validated()));
    }

    public function destroy(Request $request, Product $product, ProductPlan $plan): JsonResponse
    {
        $this->authorize('update', $product);

        $this->service->deletePlan($plan);

        return response()->json(null, 204);
    }
}
