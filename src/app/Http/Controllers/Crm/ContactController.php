<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Activity\Services\ActivityService;
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
use Illuminate\Support\Facades\DB;

/**
 * Thin CRM Contact controller (ARCHITECTURE.md §1).
 * FormRequest → one service call → Resource.
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $service,
        private readonly ActivityService $activityService,
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

        // KPI: deal participation count (via deal_contacts join), open tasks count.
        // Both are single aggregate queries — zero N+1.
        $dealsCount = (int) DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->where('deal_contacts.contact_id', $contact->id)
            ->whereNull('deals.deleted_at')
            ->count();

        $openTasksCount = $this->activityService->openTasksCountForContact((int) $contact->id);

        return ContactResource::make(
            $contact->load(['owner', 'companyLinks.company', 'channels'])
        )->additional([
            'kpi' => [
                'deals_count' => $dealsCount,
                'last_touch_at' => $contact->last_activity_at?->toIso8601String(),
                'open_tasks_count' => $openTasksCount,
            ],
        ]);
    }

    public function update(UpdateContactRequest $request, Contact $contact): JsonResource
    {
        $updated = $this->service->update($contact, $request->validated(), $request->user());

        return ContactResource::make($updated->load(['owner', 'companyLinks.company']));
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $this->service->delete($contact);

        return response()->json(['message' => 'Contact deleted.'], 200);
    }
}
