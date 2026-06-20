<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Enums\EmploymentStatus;
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
use Illuminate\Support\Facades\DB;

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

        // B6: aggregate deal totals (cross-domain via public DealService method).
        $dealTotals = $this->dealService->aggregateForCompany($company);

        // KPI: employees (current contact links — excludes people who left),
        // direct subsidiaries, documents. All aggregate queries — zero N+1.
        $employeesCount = (int) DB::table('crm_contact_company_links')
            ->where('company_id', $company->id)
            ->where('employment_status', EmploymentStatus::Works->value)
            ->count();

        $holdingCompanyCount = (int) DB::table('crm_companies')
            ->where('holding_id', $company->id)
            ->whereNull('deleted_at')
            ->count();

        $documentsCount = (int) DB::table('documents')
            ->where('source_company_id', $company->id)
            ->whereNull('archived_at')
            ->count();

        return CompanyResource::make(
            $company->load(['companyType', 'responsibleUser', 'ownerUser', 'contactLinks.contact'])
        )->additional([
            'deal_totals' => $dealTotals->toArray(),
            'kpi' => [
                'open_deals_count' => $dealTotals->open_count,
                'deals_sum' => $dealTotals->base_total,
                'deals_sum_currency' => $dealTotals->base_currency,
                'employees_count' => $employeesCount,
                'documents_count' => $documentsCount,
                'last_activity_at' => $company->last_activity_at?->toIso8601String(),
            ],
            'holding_company_count' => $holdingCompanyCount,
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResource
    {
        $updated = $this->service->update($company, $request->validated(), $request->user());

        return CompanyResource::make($updated->load(['companyType', 'responsibleUser', 'ownerUser']));
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $this->service->delete($company);

        return response()->json(['message' => 'Company deleted.'], 200);
    }
}
