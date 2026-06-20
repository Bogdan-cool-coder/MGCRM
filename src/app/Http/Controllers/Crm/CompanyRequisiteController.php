<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Services\CompanyRequisiteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCompanyRequisiteRequest;
use App\Http\Requests\Crm\UpdateCompanyRequisiteRequest;
use App\Http\Resources\Crm\CompanyRequisiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Company Requisites sub-resource controller.
 *
 * All routes are scoped under /companies/{company}/requisites.
 * IDOR: Policy::update() on the parent Company gates access (same pattern
 * as CompanyEmployeeController).
 */
class CompanyRequisiteController extends Controller
{
    public function __construct(
        private readonly CompanyRequisiteService $service,
    ) {}

    /**
     * GET /companies/{company}/requisites
     */
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $requisites = $this->service->list($company);

        return CompanyRequisiteResource::collection($requisites);
    }

    /**
     * POST /companies/{company}/requisites
     */
    public function store(StoreCompanyRequisiteRequest $request, Company $company): JsonResponse
    {
        $requisite = $this->service->create($company, $request->validated());

        return CompanyRequisiteResource::make($requisite)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /companies/{company}/requisites/{requisite}
     */
    public function update(
        UpdateCompanyRequisiteRequest $request,
        Company $company,
        CompanyRequisite $requisite,
    ): JsonResponse {
        abort_if((int) $requisite->company_id !== $company->id, 404);

        $requisite = $this->service->update($requisite, $request->validated());

        return CompanyRequisiteResource::make($requisite)->response();
    }

    /**
     * DELETE /companies/{company}/requisites/{requisite}
     */
    public function destroy(Request $request, Company $company, CompanyRequisite $requisite): JsonResponse
    {
        $this->authorize('update', $company);

        abort_if((int) $requisite->company_id !== $company->id, 404);

        $this->service->delete($requisite);

        return response()->json(['message' => 'Реквизиты удалены.']);
    }

    /**
     * POST /companies/{company}/requisites/{requisite}/set-current
     * Makes the given set the active/current requisites for the company and
     * mirrors fields back to crm_companies (denorm).
     */
    public function setCurrent(Request $request, Company $company, CompanyRequisite $requisite): JsonResponse
    {
        $this->authorize('update', $company);

        abort_if((int) $requisite->company_id !== $company->id, 404);

        $requisite = $this->service->setCurrent($requisite);

        return CompanyRequisiteResource::make($requisite)->response();
    }

    /**
     * GET /companies/{company}/requisites/resolve
     * Helper for contract/deal creation: returns auto-selected requisite or
     * the list + needs_selection flag when there are multiple sets.
     */
    public function resolve(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $result = $this->service->resolveForNewDocument($company);

        if (! ($result['needs_selection'] ?? false)) {
            $requisite = $result['requisite'] ?? null;

            return response()->json([
                'needs_selection' => false,
                'requisite' => $requisite
                    ? CompanyRequisiteResource::make($requisite)->resolve()
                    : null,
            ]);
        }

        return response()->json([
            'needs_selection' => true,
            'requisites' => CompanyRequisiteResource::collection($result['requisites'])->resolve(),
        ]);
    }
}
