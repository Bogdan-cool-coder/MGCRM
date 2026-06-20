<?php

declare(strict_types=1);

namespace App\Domain\Crm\Listeners;

use App\Domain\Contracts\Events\TerminationAgreementSigned;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\DisconnectReason;
use App\Domain\Crm\Services\CompanyService;
use Illuminate\Support\Facades\Log;

/**
 * DisconnectCompanyOnTerminationSigned (N6) — CRM listener for the
 * Contracts-domain TerminationAgreementSigned event.
 *
 * When a TerminationAgreement Document is signed (scan uploaded + operator
 * marks it signed), this listener finalises the disconnect by calling
 * CompanyService::disconnect().
 *
 * reasonId is stored in context.custom.disconnect_reason_id on the Document
 * itself — written by CompanyDisconnectService::initiate().  This avoids any
 * mutable state on the Company record between initiate() and finalise().
 *
 * Idempotency: if the company is already 'disconnected' the listener is a
 * no-op (logs a warning).  This guards against TerminationAgreementSigned
 * firing twice (e.g. re-upload of signed scan).
 *
 * Registered in AppServiceProvider::boot() via Event::listen.
 */
class DisconnectCompanyOnTerminationSigned
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {}

    public function handle(TerminationAgreementSigned $event): void
    {
        $company = Company::find($event->companyId);

        if ($company === null) {
            Log::warning('DisconnectCompanyOnTerminationSigned: company not found', [
                'company_id' => $event->companyId,
                'document_id' => $event->documentId,
            ]);

            return;
        }

        // Idempotency guard: company is already disconnected — no-op.
        if ($company->client_status === ClientStatus::Disconnected) {
            Log::info('DisconnectCompanyOnTerminationSigned: company already disconnected, skipping', [
                'company_id' => $event->companyId,
                'document_id' => $event->documentId,
            ]);

            return;
        }

        // Retrieve the reasonId from the Document's context.custom where it was
        // stored by CompanyDisconnectService::initiate().
        $doc = Document::find($event->documentId);
        $reasonId = (int) ($doc?->context['custom']['disconnect_reason_id'] ?? 0);

        if ($reasonId === 0) {
            Log::error('DisconnectCompanyOnTerminationSigned: disconnect_reason_id missing in document context', [
                'company_id' => $event->companyId,
                'document_id' => $event->documentId,
            ]);

            // Still proceed — reason fallback to null keeps the disconnect operational
            // (CompanyService::disconnect accepts reasonId as int so we use 0; caller
            // must ensure reason exists, but we must not block the status change).
            // Logging is sufficient — the operator can fix the log record manually.
        }

        $this->companyService->disconnect(
            $company,
            $reasonId !== 0 ? $reasonId : $this->resolveFallbackReasonId(),
            $event->documentId,
            null, // system action (no HTTP actor)
        );
    }

    /**
     * Provide a last-resort fallback reasonId when none is stored on the Document.
     *
     * Returns the first active disconnect_reason or 0 if the table is empty.
     * A 0 is technically invalid (no FK row), so this path must not occur in
     * production — the Log::error above will alert the operator.
     */
    private function resolveFallbackReasonId(): int
    {
        return (int) DisconnectReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->value('id');
    }
}
