<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\Country;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDirectoryRequest;
use App\Http\Resources\Crm\CountryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $countries = Country::where('is_active', true)->orderBy('sort_order')->get();

        return CountryResource::collection($countries);
    }

    public function store(StoreDirectoryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $country = Country::create($request->validated());

        return CountryResource::make($country);
    }

    public function show(Request $request, Country $country): JsonResource
    {
        return CountryResource::make($country);
    }

    public function update(StoreDirectoryRequest $request, Country $country): JsonResource
    {
        $this->authorize('admin-write');

        $country->update($request->validated());

        return CountryResource::make($country->fresh());
    }

    public function destroy(Request $request, Country $country): JsonResponse
    {
        $this->authorize('admin-write');

        $country->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
