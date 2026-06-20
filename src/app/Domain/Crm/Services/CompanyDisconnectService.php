<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\TerminationDocumentService;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\DisconnectReason;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * CompanyDisconnectService — orchestrates the two-phase disconnect flow (N6).
 *
 * Phase 1 (initiate): creates a TerminationAgreement Document and leaves the
 *   company status UNCHANGED (still active/prospect).  The reasonId is persisted
 *   inside context.custom.disconnect_reason_id on the Document so it can be
 *   retrieved atomically when the signed-scan event fires.
 *
 * Phase 2 (finalise): handled by DisconnectCompanyOnTerminationSigned listener
 *   which calls CompanyService::disconnect() — the internal finaliser.
 *
 * Boundaries:
 *   - Does NOT call CompanyService::disconnect() — that is reserved for the listener.
 *   - Does NOT touch Document generation / scanning — that is the Contracts domain.
 *   - Does NOT change company client_status.
 */
class CompanyDisconnectService
{
    public function __construct(
        private readonly TerminationDocumentService $terminationDocumentService,
    ) {}

    /**
     * Initiate the disconnect flow for a company.
     *
     * Resolves the reason label from the directory and passes it as
     * `termination_reason` into context.custom together with the provided
     * termination_date.  Stores disconnect_reason_id in context.custom so the
     * event listener can look it up without querying the company record again.
     *
     * Company status is NOT changed.  The caller receives the draft Document
     * so it can hand the document ID to the front-end for generation / scan upload.
     *
     * @param  \DateTimeInterface|string  $terminationDate  The effective termination date (YYYY-MM-DD or DateTimeInterface).
     * @param  int  $authorUserId  Actor initiating the disconnect (for Document.author_user_id).
     * @param  array<string, mixed>  $extra  Optional extra fields forwarded to TerminationDocumentService::create()
     *                                       (company_requisite_id, country_code, city, currency, product_code,
     *                                       context.custom.original_contract_number / original_contract_date /
     *                                       termination_signatory).
     *
     * @throws ValidationException when no requisite can be resolved
     */
    public function initiate(
        Company $company,
        int $reasonId,
        \DateTimeInterface|string $terminationDate,
        int $authorUserId,
        array $extra = [],
    ): Document {
        $reason = DisconnectReason::findOrFail($reasonId);

        $terminationDateStr = $terminationDate instanceof \DateTimeInterface
            ? Carbon::instance($terminationDate)->toDateString()
            : (string) $terminationDate;

        // Merge caller-supplied custom context with our mandatory disconnect fields.
        // The disconnect_reason_id is embedded here so the listener can retrieve it
        // from the Document record without touching the Company during the async gap.
        $userCustom = (array) ($extra['context']['custom'] ?? []);

        $extra['context']['custom'] = array_merge($userCustom, [
            'termination_date' => $terminationDateStr,
            'termination_reason' => $reason->name,
            'disconnect_reason_id' => $reasonId,
        ]);

        return $this->terminationDocumentService->create($company, $extra, $authorUserId);
    }
}
