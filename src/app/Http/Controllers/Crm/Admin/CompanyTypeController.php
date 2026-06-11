<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm\Admin;

use App\Domain\Crm\Models\CompanyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDirectoryRequest;
use App\Http\Resources\Crm\CompanyTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $types = CompanyType::orderBy('sort_order')->orderBy('name')->get();

        return CompanyTypeResource::collection($types);
    }

    public function store(StoreDirectoryRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $type = CompanyType::create($request->validated());

        return CompanyTypeResource::make($type);
    }

    public function show(Request $request, CompanyType $companyType): JsonResource
    {
        return CompanyTypeResource::make($companyType);
    }

    public function update(StoreDirectoryRequest $request, CompanyType $companyType): JsonResource
    {
        $this->authorize('admin-write');

        $companyType->update($request->validated());

        return CompanyTypeResource::make($companyType->fresh());
    }

    public function destroy(Request $request, CompanyType $companyType): JsonResponse
    {
        $this->authorize('admin-write');

        $companyType->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
