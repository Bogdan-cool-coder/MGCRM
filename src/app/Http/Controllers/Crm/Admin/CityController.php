<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\City;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDirectoryRequest;
use App\Http\Resources\Crm\CityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = City::orderBy('sort_order')->orderBy('name');

        if ($request->has('country_code')) {
            $query->where('country_code', $request->query('country_code'));
        }

        return CityResource::collection($query->get());
    }

    public function store(StoreDirectoryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $city = City::create($request->validated());

        return CityResource::make($city);
    }

    public function show(Request $request, City $city): JsonResource
    {
        return CityResource::make($city);
    }

    public function update(StoreDirectoryRequest $request, City $city): JsonResource
    {
        $this->authorize('admin-write');

        $city->update($request->validated());

        return CityResource::make($city->fresh());
    }

    public function destroy(Request $request, City $city): JsonResponse
    {
        $this->authorize('admin-write');

        $city->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
