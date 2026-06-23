<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\Country;
use App\Domain\Crm\Services\CountryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCountryRequest;
use App\Http\Requests\Crm\UpdateCountryRequest;
use App\Http\Resources\Crm\CountryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin admin controller for the Countries directory.
 *
 * Read: any authenticated user.
 * Write: admin/director only (admin-write gate).
 *
 * GET ?active_only=1  — only is_active=true rows (used by selects / dropdowns).
 *                       Without the param: all rows for the admin settings table.
 */
class CountryController extends Controller
{
    public function __construct(private readonly CountryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $activeOnly = $request->boolean('active_only');

        return CountryResource::collection($this->service->list($activeOnly));
    }

    public function store(StoreCountryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $country = $this->service->create($request->validated());

        return CountryResource::make($country);
    }

    public function show(Request $request, Country $country): JsonResource
    {
        return CountryResource::make($country);
    }

    public function update(UpdateCountryRequest $request, Country $country): JsonResource
    {
        $this->authorize('admin-write');

        $country = $this->service->update($country, $request->validated());

        return CountryResource::make($country);
    }

    public function destroy(Request $request, Country $country): JsonResponse
    {
        $this->authorize('admin-write');

        try {
            $this->service->delete($country);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Deleted.']);
    }
}
