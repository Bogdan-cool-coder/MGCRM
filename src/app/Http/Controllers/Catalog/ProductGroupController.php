<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Services\ProductGroupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductGroupRequest;
use App\Http\Requests\Catalog\UpdateProductGroupRequest;
use App\Http\Resources\Catalog\ProductGroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductGroupController extends Controller
{
    public function __construct(
        private readonly ProductGroupService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductGroup::class);

        return ProductGroupResource::collection($this->service->list($request->query()));
    }

    public function store(StoreProductGroupRequest $request): JsonResponse
    {
        $group = $this->service->create($request->validated());

        return ProductGroupResource::make($group)->response()->setStatusCode(201);
    }

    public function show(Request $request, ProductGroup $productGroup): JsonResource
    {
        $this->authorize('view', $productGroup);

        return ProductGroupResource::make($productGroup);
    }

    public function update(UpdateProductGroupRequest $request, ProductGroup $productGroup): JsonResource
    {
        return ProductGroupResource::make($this->service->update($productGroup, $request->validated()));
    }

    public function destroy(Request $request, ProductGroup $productGroup): JsonResponse
    {
        $this->authorize('delete', $productGroup);

        $this->service->delete($productGroup);

        return response()->json(null, 204);
    }
}
