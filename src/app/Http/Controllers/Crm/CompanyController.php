<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCompanyRequest;
use App\Http\Requests\Crm\UpdateCompanyRequest;
use App\Http\Resources\Crm\CompanyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin CRM Company controller (ARCHITECTURE.md §1):
 * FormRequest → one service call → Resource. No Eloquent, no logic here.
 *
 * IDOR: 404-on-foreign is enforced by the Policy returning false for
 * inaccessible companies; Laravel will respond with 403 which our
 * 404-on-foreign middleware converts to 404 for item/sub-resource routes.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyService $service,
        private readonly DealService $dealService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $companies = $this->service->list($request->query(), (int) $request->query('per_page', 25));

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request): JsonResource
    {
        $company = $this->service->create($request->validated(), $request->user());

        return CompanyResource::make($company->load(['companyType', 'responsibleUser', 'ownerUser']));
    }

    public function show(Request $request, Company $company): JsonResource
    {
        $this->authorize('view', $company);

        // B6: aggregate deal totals (cross-domain via public DealService method)
        $dealTotals = $this->dealService->aggregateForCompany($company);

        return CompanyResource::make(
            $company->load(['companyType', 'responsibleUser', 'ownerUser', 'contactLinks.contact'])
        )->additional(['deal_totals' => $dealTotals->toArray()]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResource
    {
        $updated = $this->service->update($company, $request->validated());

        return CompanyResource::make($updated->load(['companyType', 'responsibleUser', 'ownerUser']));
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $this->service->delete($company);

        return response()->json(['message' => 'Company deleted.'], 200);
    }
}
