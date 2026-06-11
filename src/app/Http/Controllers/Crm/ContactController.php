<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreContactRequest;
use App\Http\Requests\Crm\UpdateContactRequest;
use App\Http\Resources\Crm\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin CRM Contact controller (ARCHITECTURE.md §1).
 * FormRequest → one service call → Resource.
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $contacts = $this->service->list($request->query(), (int) $request->query('per_page', 25));

        return ContactResource::collection($contacts);
    }

    public function store(StoreContactRequest $request): JsonResource
    {
        $contact = $this->service->create($request->validated(), $request->user());

        return ContactResource::make($contact->load(['owner', 'companyLinks.company']));
    }

    public function show(Request $request, Contact $contact): JsonResource
    {
        $this->authorize('view', $contact);

        return ContactResource::make($contact->load(['owner', 'companyLinks.company']));
    }

    public function update(UpdateContactRequest $request, Contact $contact): JsonResource
    {
        $updated = $this->service->update($contact, $request->validated());

        return ContactResource::make($updated->load(['owner', 'companyLinks.company']));
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $this->service->delete($contact);

        return response()->json(['message' => 'Contact deleted.'], 200);
    }
}
