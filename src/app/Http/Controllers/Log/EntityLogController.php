<?php

declare(strict_types=1);

namespace App\Http\Controllers\Log;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Models\Deal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Log\EntityLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin read-only controller for an entity's polymorphic action log.
 *
 * GET /api/deals/{deal}/log
 * GET /api/companies/{company}/log
 * GET /api/contacts/{contact}/log
 *
 * The log inherits the subject's visibility: each method authorizes 'view' on
 * the underlying entity through its existing policy (DealPolicy / CompanyPolicy /
 * ContactPolicy), so whoever can see the entity can see its log and no one else.
 * Pagination + ordering (newest first) live in EntityLogService.
 */
class EntityLogController extends Controller
{
    public function __construct(
        private readonly EntityLogService $logs,
    ) {}

    public function dealLog(Request $request, Deal $deal): AnonymousResourceCollection
    {
        $this->authorize('view', $deal);

        return EntityLogResource::collection(
            $this->logs->forSubject(LogSubjectType::Deal, (int) $deal->id, $this->perPage($request)),
        );
    }

    public function companyLog(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        return EntityLogResource::collection(
            $this->logs->forSubject(LogSubjectType::Company, (int) $company->id, $this->perPage($request)),
        );
    }

    public function contactLog(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        return EntityLogResource::collection(
            $this->logs->forSubject(LogSubjectType::Contact, (int) $contact->id, $this->perPage($request)),
        );
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', '30');

        return max(1, min($perPage, 100));
    }
}
