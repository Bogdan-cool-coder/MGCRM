<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\ContactsKpiService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for the Contacts-section KPI chip bar (Contacts-spec.md §3).
 *
 * GET /api/contacts/kpi?entity=company|contact
 *
 * Delegates all counting to ContactsKpiService.
 * Auth: requires authentication (Sanctum); uses the same Policy viewAny gate
 * as the list endpoints (Company::viewAny / Contact::viewAny).
 */
class ContactsKpiController extends Controller
{
    public function __construct(
        private readonly ContactsKpiService $service,
    ) {}

    /**
     * GET /api/contacts/kpi?entity=company|contact
     *
     * Response shape:
     *   entity=company → { entity: 'company', total, clients, cat_l, cat_m, cat_s, new_week }
     *   entity=contact → { entity: 'contact', total, active, no_touch_30, new_week }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $entity = $request->query('entity', 'company');

        if ($entity === 'company') {
            $this->authorize('viewAny', Company::class);

            $stats = $this->service->forCompanies($request->user());

            return response()->json([
                'data' => array_merge(['entity' => 'company'], $stats),
            ]);
        }

        // Default / 'contact'
        $this->authorize('viewAny', Contact::class);

        $stats = $this->service->forContacts($request->user());

        return response()->json([
            'data' => array_merge(['entity' => 'contact'], $stats),
        ]);
    }
}
