<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Services\LostReasonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreLostReasonRequest;
use App\Http\Requests\Sales\UpdateLostReasonRequest;
use App\Http\Requests\Shared\ReorderDirectoryRequest;
use App\Http\Resources\Sales\LostReasonResource;
use App\Support\SortOrderReorderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin LostReason controller. Reads are open; writes are admin/director (policy).
 */
class LostReasonController extends Controller
{
    public function __construct(
        private readonly LostReasonService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LostReason::class);

        $activeOnly = $request->boolean('active_only');

        return LostReasonResource::collection($this->service->list($activeOnly));
    }

    /**
     * PATCH /lost-reasons/reorder
     *
     * Drag-and-drop reorder. Array order of items[] wins; sort_order is renumbered
     * 1..n (see SortOrderReorderer). Same write gate as create (LostReasonPolicy —
     * admin/director).
     */
    public function reorder(ReorderDirectoryRequest $request): JsonResponse
    {
        $this->authorize('create', LostReason::class);

        SortOrderReorderer::apply(LostReason::class, $request->orderedIds());

        return response()->json(['message' => 'Order updated.']);
    }

    public function store(StoreLostReasonRequest $request): JsonResource
    {
        $lostReason = $this->service->create($request->validated());

        return LostReasonResource::make($lostReason);
    }

    public function update(UpdateLostReasonRequest $request, LostReason $lostReason): JsonResource
    {
        $updated = $this->service->update($lostReason, $request->validated());

        return LostReasonResource::make($updated);
    }

    public function destroy(Request $request, LostReason $lostReason): Response
    {
        $this->authorize('delete', $lostReason);

        $this->service->delete($lostReason);

        return response()->noContent();
    }
}
