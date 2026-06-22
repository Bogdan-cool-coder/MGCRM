<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyRequisiteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TerminationDocumentService — creates and manages ДС о расторжении.
 *
 * Flow:
 *   1. Resolve company requisites pin (current or passed explicitly).
 *   2. Find the latest signed kind=contract Document for the company to pre-fill
 *      original_contract_number / original_contract_date from its number + signed_at.
 *   3. Create Document (kind=termination_agreement, status=draft).
 *   4. Set context.custom with 5 termination variables.
 *
 * Status machine for ДС: draft → (approved) → signed.
 * The simplified flow skips approval routing — operator goes straight to signed via
 * scan upload (guardSign in DocumentService already enforces signed_scan).
 * The domain does NOT bypass the state machine: draft→signed is NOT valid.
 * The caller transitions draft→approved first (or the UI does), then →signed.
 *
 * Boundaries:
 *   - Does NOT touch CRM services beyond CompanyRequisiteService::current().
 *   - Does NOT dispatch TerminationAgreementSigned — that is handled in
 *     DocumentService::applySigned() which calls dispatchTerminationSigned() here.
 */
class TerminationDocumentService
{
    public function __construct(
        private readonly CompanyRequisiteService $requisiteService,
    ) {}

    /**
     * Create a TerminationAgreement Document for the given company.
     *
     * @param  array<string, mixed>  $data
     *                                      Required: source_company_id
     *                                      Optional: company_requisite_id, context.custom.* (5 variables), country_code, city, currency
     *
     * @throws ValidationException when company requisite cannot be resolved
     */
    public function create(Company $company, array $data, int $authorUserId): Document
    {
        return DB::transaction(function () use ($company, $data, $authorUserId): Document {
            // 1. Resolve requisite pin
            $requisiteId = $data['company_requisite_id'] ?? null;
            if ($requisiteId === null) {
                $requisite = $this->requisiteService->current($company);
                if ($requisite !== null) {
                    $requisiteId = $requisite->id;
                }
            }

            // 2. Auto-fill original contract info from latest signed contract for the company
            $autoFilled = $this->resolveOriginalContract($company);

            // 3. Merge user-provided custom fields, keeping auto-filled as defaults
            $userCustom = (array) ($data['context']['custom'] ?? []);
            $customContext = array_merge($autoFilled, $userCustom);

            // 4. Create the document
            $doc = Document::create([
                'kind' => DocumentKind::TerminationAgreement->value,
                'product_code' => $data['product_code'] ?? 'macrocrm',
                'country_code' => $data['country_code'] ?? $company->country_code ?? 'uz',
                'city' => $data['city'] ?? $company->city ?? null,
                'currency' => $data['currency'] ?? 'UZS',
                'source_company_id' => $company->id,
                'company_requisite_id' => $requisiteId,
                'author_user_id' => $authorUserId,
                'status' => ContractStatus::Draft->value,
                'context' => [
                    'sublicensee' => [],
                    'license' => [],
                    'contract' => [],
                    'payments' => [],
                    'acts' => [],
                    'custom' => $customContext,
                ],
                'extra_fields' => [],
                'subtotal' => 0,
                'discount_pct' => 0,
                'discount_amount' => 0,
                'total' => 0,
            ]);

            return $doc;
        });
    }

    // ---- Private helpers ----

    /**
     * Find the latest signed contract for this company and extract
     * original_contract_number + original_contract_date as auto-fill defaults.
     *
     * @return array<string, string>
     */
    private function resolveOriginalContract(Company $company): array
    {
        $original = Document::query()
            ->where('source_company_id', $company->id)
            ->where('kind', DocumentKind::Contract->value)
            ->where('status', ContractStatus::Signed->value)
            ->orderByDesc('signed_at')
            ->first();

        if ($original === null) {
            return [];
        }

        $result = [];

        if ($original->number !== null) {
            $result['original_contract_number'] = (string) $original->number;
        }

        if ($original->signed_at !== null) {
            $date = $original->signed_at instanceof Carbon
                ? $original->signed_at
                : Carbon::parse($original->signed_at);
            $result['original_contract_date'] = $date->format('d.m.Y');
        }

        return $result;
    }
}
