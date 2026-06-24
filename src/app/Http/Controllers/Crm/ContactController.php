<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\IndexContactRequest;
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
        private readonly DealService $dealService,
    ) {}

    public function index(IndexContactRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        // Inject the auth user ID so the service can apply `only_mine` without
        // knowing about HTTP/Auth; the service never reads Auth directly.
        $filters['_auth_user_id'] = $request->user()?->id;

        $contacts = $this->service->list($filters, $request->user(), (int) ($filters['per_page'] ?? 25));

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

        // KPI: deal participation count + deal sum (via deal_contacts join), open tasks count.
        // All aggregate queries — zero N+1.
        $dealsCount = (int) DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->where('deal_contacts.contact_id', $contact->id)
            ->whereNull('deals.deleted_at')
            ->count();

        $openTasksCount = $this->activityService->openTasksCountForContact((int) $contact->id);

        // B-2 (DS-5): aggregate deal amounts for the contact's KPI sum chip.
        $dealTotals = $this->dealService->aggregateForContact($contact);

        // KPI companies count: how many companies this contact is linked to.
        $companiesCount = (int) DB::table('crm_contact_company_links')
            ->where('contact_id', $contact->id)
            ->count();

        return ContactResource::make(
            $contact->load(['owner', 'companyLinks.company', 'channels'])
        )->additional([
            'kpi' => [
                'deals_count' => $dealsCount,
                'deals_sum' => $dealTotals->base_total,          // int kopecks or null if FX unavailable
                'deals_sum_currency' => $dealTotals->base_currency, // ISO 4217
                'last_touch_at' => $contact->last_activity_at?->toIso8601String(),
                'open_tasks_count' => $openTasksCount,
                'companies_count' => $companiesCount,
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
