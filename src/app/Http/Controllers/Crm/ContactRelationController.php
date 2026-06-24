<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactRelation;
use App\Domain\Crm\Services\ContactRelationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreContactRelationRequest;
use App\Http\Requests\Crm\UpdateContactRelationRequest;
use App\Http\Resources\Crm\ContactRelationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin controller for contact-to-contact relations (ARCHITECTURE.md §1).
 * Routes: GET/POST /contacts/{contact}/relations
 *         PATCH/DELETE /contacts/{contact}/relations/{relation}
 *
 * Pattern: 1-in-1 with ContactChannelController.
 */
class ContactRelationController extends Controller
{
    public function __construct(
        private readonly ContactRelationService $service,
    ) {}

    public function index(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $relations = $this->service->list($contact);

        return ContactRelationResource::collection($relations);
    }

    public function store(StoreContactRelationRequest $request, Contact $contact): JsonResource
    {
        $relation = $this->service->attach($contact, $request->validated(), $request->user());
        $relation->load(['contact', 'relatedContact', 'createdBy']);

        return ContactRelationResource::make($relation);
    }

    public function update(UpdateContactRelationRequest $request, Contact $contact, ContactRelation $relation): JsonResource
    {
        // Ensure the relation involves the route-bound contact (prevent IDOR).
        // Relations are bidirectional: contact_id or related_contact_id must match.
        abort_unless(
            (int) $relation->contact_id === (int) $contact->id
            || (int) $relation->related_contact_id === (int) $contact->id,
            404,
        );

        $updated = $this->service->update($relation, $request->validated());
        $updated->load(['contact', 'relatedContact', 'createdBy']);

        return ContactRelationResource::make($updated);
    }

    public function destroy(Request $request, Contact $contact, ContactRelation $relation): JsonResponse
    {
        // Ensure the relation involves the route-bound contact (prevent IDOR).
        abort_unless(
            (int) $relation->contact_id === (int) $contact->id
            || (int) $relation->related_contact_id === (int) $contact->id,
            404,
        );

        $this->authorize('delete', $relation);

        $this->service->detach($relation);

        return response()->json(['message' => 'Relation deleted.']);
    }
}
