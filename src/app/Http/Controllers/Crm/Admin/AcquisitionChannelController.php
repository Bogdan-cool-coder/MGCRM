<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\AcquisitionChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreAcquisitionChannelRequest;
use App\Http\Requests\Crm\UpdateAcquisitionChannelRequest;
use App\Http\Resources\Crm\AcquisitionChannelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin admin controller for the Acquisition Channels directory.
 * Read: any authenticated user. Write: admin/director only (admin-write gate).
 */
class AcquisitionChannelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $activeOnly = $request->boolean('active_only');

        $channels = AcquisitionChannel::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return AcquisitionChannelResource::collection($channels);
    }

    public function store(StoreAcquisitionChannelRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $channel = AcquisitionChannel::create($request->validated());

        return AcquisitionChannelResource::make($channel);
    }

    public function show(Request $request, AcquisitionChannel $acquisitionChannel): JsonResource
    {
        return AcquisitionChannelResource::make($acquisitionChannel);
    }

    public function update(UpdateAcquisitionChannelRequest $request, AcquisitionChannel $acquisitionChannel): JsonResource
    {
        $this->authorize('admin-write');

        $acquisitionChannel->update($request->validated());

        return AcquisitionChannelResource::make($acquisitionChannel->fresh());
    }

    public function destroy(Request $request, AcquisitionChannel $acquisitionChannel): JsonResponse
    {
        $this->authorize('admin-write');

        $acquisitionChannel->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
