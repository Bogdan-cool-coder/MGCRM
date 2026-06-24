<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Services\ApprovalRouteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreApprovalRouteRequest;
use App\Http\Requests\Contracts\UpdateApprovalRouteRequest;
use App\Http\Resources\Contracts\ApprovalRouteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalRouteController extends Controller
{
    public function __construct(
        private readonly ApprovalRouteService $service,
    ) {}

    /**
     * GET /api/approval-routes
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApprovalRoute::class);

        $routes = ApprovalRoute::query()
            ->with('template:id,code')
            ->orderBy('document_kind')
            ->orderBy('title')
            ->get();

        return ApprovalRouteResource::collection($routes);
    }

    /**
     * POST /api/approval-routes
     */
    public function store(StoreApprovalRouteRequest $request): JsonResponse
    {
        $this->authorize('create', ApprovalRoute::class);

        $route = $this->service->create(
            $request->validated(),
            $request->user()->id,
        );

        return ApprovalRouteResource::make($route)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/approval-routes/{route}
     */
    public function show(ApprovalRoute $approvalRoute): JsonResource
    {
        $this->authorize('view', $approvalRoute);

        return ApprovalRouteResource::make($approvalRoute);
    }

    /**
     * PATCH /api/approval-routes/{route}
     */
    public function update(UpdateApprovalRouteRequest $request, ApprovalRoute $approvalRoute): JsonResource
    {
        $this->authorize('update', $approvalRoute);

        $updated = $this->service->update($approvalRoute, $request->validated(), $request->user()->id);

        return ApprovalRouteResource::make($updated);
    }

    /**
     * DELETE /api/approval-routes/{route}
     * Soft-delete: sets is_active=false, does not destroy the record.
     */
    public function destroy(ApprovalRoute $approvalRoute): JsonResponse
    {
        $this->authorize('delete', $approvalRoute);

        $this->service->deactivate($approvalRoute);

        return response()->json(null, 204);
    }
}
