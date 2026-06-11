<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\LinkContactCompanyRequest;
use App\Http\Resources\Crm\ContactCompanyLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Manages the M2M company links on a Contact.
 * Routes: /contacts/{contact}/companies
 */
class ContactCompanyController extends Controller
{
    public function __construct(
        private readonly ContactService $service,
    ) {}

    public function index(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $links = $contact->companyLinks()->with('company')->get();

        return ContactCompanyLinkResource::collection($links);
    }

    /**
     * POST /contacts/{contact}/companies
     * Body: company_id + link fields.
     */
    public function store(LinkContactCompanyRequest $request, Contact $contact): JsonResource
    {
        $this->authorize('manageLinks', $contact);

        $companyId = (int) $request->input('company_id');
        $link = $this->service->linkCompany($contact, $companyId, $request->validated());

        return ContactCompanyLinkResource::make($link->load(['company', 'contact']));
    }

    /**
     * DELETE /contacts/{contact}/companies/{company}
     */
    public function destroy(Request $request, Contact $contact, int $company): JsonResponse
    {
        $this->authorize('manageLinks', $contact);

        $this->service->unlinkCompany($contact, $company);

        return response()->json(['message' => 'Company link removed.']);
    }

    /**
     * POST /contacts/{contact}/companies/{company}/primary
     * Reassigns primary company for the contact.
     */
    public function setPrimary(Request $request, Contact $contact, int $company): JsonResource
    {
        $this->authorize('manageLinks', $contact);

        $link = $this->service->reassignPrimary($contact, $company);

        return ContactCompanyLinkResource::make($link->load(['company', 'contact']));
    }
}
