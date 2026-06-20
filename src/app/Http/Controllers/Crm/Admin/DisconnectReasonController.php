<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\DisconnectReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDisconnectReasonRequest;
use App\Http\Requests\Crm\UpdateDisconnectReasonRequest;
use App\Http\Resources\Crm\DisconnectReasonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin admin controller for the Disconnect Reasons directory.
 * Read: any authenticated user. Write: admin/director only (admin-write gate).
 */
class DisconnectReasonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $activeOnly = $request->boolean('active_only');

        $reasons = DisconnectReason::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return DisconnectReasonResource::collection($reasons);
    }

    public function store(StoreDisconnectReasonRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $reason = DisconnectReason::create($request->validated());

        return DisconnectReasonResource::make($reason);
    }

    public function show(Request $request, DisconnectReason $disconnectReason): JsonResource
    {
        return DisconnectReasonResource::make($disconnectReason);
    }

    public function update(UpdateDisconnectReasonRequest $request, DisconnectReason $disconnectReason): JsonResource
    {
        $this->authorize('admin-write');

        $disconnectReason->update($request->validated());

        return DisconnectReasonResource::make($disconnectReason->fresh());
    }

    public function destroy(Request $request, DisconnectReason $disconnectReason): JsonResponse
    {
        $this->authorize('admin-write');

        $disconnectReason->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
