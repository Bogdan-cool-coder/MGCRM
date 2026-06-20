<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\LinkContactCompanyRequest;
use App\Http\Resources\Crm\ContactCompanyLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Manages the M2M employee links on a Company.
 * Routes: /companies/{company}/employees
 */
class CompanyEmployeeController extends Controller
{
    public function __construct(
        private readonly CompanyService $service,
    ) {}

    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $links = $company->contactLinks()->with('contact')->get();

        return ContactCompanyLinkResource::collection($links);
    }

    /**
     * POST /companies/{company}/employees
     * Body: contact_id (required) + link fields.
     */
    public function store(LinkContactCompanyRequest $request, Company $company): JsonResource
    {
        $this->authorize('manageEmployees', $company);

        $contactId = (int) $request->input('contact_id');
        $link = $this->service->addEmployee($company, $contactId, $request->validated(), $request->user());

        return ContactCompanyLinkResource::make($link->load(['contact', 'company']));
    }

    /**
     * DELETE /companies/{company}/employees/{contact}
     */
    public function destroy(Request $request, Company $company, int $contact): JsonResponse
    {
        $this->authorize('manageEmployees', $company);

        $this->service->removeEmployee($company, $contact);

        return response()->json(['message' => 'Employee link removed.']);
    }
}
