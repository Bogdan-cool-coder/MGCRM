<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyDisconnectService;
use App\Domain\Crm\Services\CompanyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\InitiateDisconnectRequest;
use App\Http\Resources\Contracts\DocumentResource;
use App\Http\Resources\Crm\CompanyClientStatusLogResource;
use App\Http\Resources\Crm\CompanyResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Company lifecycle status actions (N5 / N6).
 *
 * GET  /companies/{company}/status-log          → paginated status-change log
 * POST /companies/{company}/disconnect          → initiate disconnect flow (N6):
 *                                                 creates TerminationAgreement Document,
 *                                                 DOES NOT change company status.
 *                                                 Status changes only on TerminationAgreementSigned event.
 * POST /companies/{company}/reconnect           → revert to active/prospect (N5)
 *
 * markAsUniqueClient() is called internally from DealMoveService (sales domain).
 * CompanyService::disconnect() is called internally by DisconnectCompanyOnTerminationSigned listener.
 *
 * Admin-override (direct disconnect without ДС) is intentionally NOT exposed here.
 * If ever needed, route to a dedicated admin-only controller action.
 */
class CompanyClientStatusController extends Controller
{
    public function __construct(
        private readonly CompanyService $service,
        private readonly CompanyDisconnectService $disconnectService,
    ) {}

    /**
     * Return paginated client-status change log for a company.
     * Ordered newest-first. Loads changedBy and reason for display.
     */
    public function statusLog(Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $log = $company->clientStatusLog()
            ->with(['changedBy', 'reason'])
            ->orderByDesc('changed_at')
            ->paginate(25);

        return CompanyClientStatusLogResource::collection($log);
    }

    /**
     * Initiate the disconnect flow (N6).
     *
     * Creates a TerminationAgreement Document (draft) pinned to the company.
     * Company status is NOT changed — it will transition to 'disconnected' only
     * when the operator uploads a signed scan and TerminationAgreementSigned fires.
     *
     * Returns the created Document (DocumentResource) so the front-end can
     * redirect the operator to the document screen for generation + scan upload.
     */
    public function disconnect(InitiateDisconnectRequest $request, Company $company): JsonResource
    {
        $this->authorize('update', $company);

        $validated = $request->validated();

        $doc = $this->disconnectService->initiate(
            $company,
            (int) $validated['disconnect_reason_id'],
            $validated['termination_date'],
            (int) $request->user()->id,
            $request->terminationDocumentData(),
        );

        return DocumentResource::make($doc->load('sourceCompany'));
    }

    /**
     * Revert a disconnected company back to active (or prospect if no won history).
     */
    public function reconnect(Company $company): JsonResource
    {
        $this->authorize('update', $company);

        $this->service->reconnect($company, request()->user()?->id);

        return CompanyResource::make($company->fresh()->load(['companyType', 'responsibleUser', 'ownerUser']));
    }
}
