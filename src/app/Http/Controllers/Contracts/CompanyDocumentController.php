<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Crm\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\GenerateDocumentRequest;
use App\Http\Resources\Contracts\GenerateResultResource;
use Illuminate\Validation\ValidationException;

/**
 * CompanyDocumentController — generate a Document from a Company context.
 *
 * POST /api/companies/{company}/documents/generate
 *
 * Resolves or creates a Document linked to the company, then delegates to
 * ContractGenerationService::generate().
 *
 * If document_id is passed in body: use that document (must belong to company).
 * Otherwise: find the first draft/needs_rework document for the company, or create one.
 * Creating a new document requires product_code + country_code in the request body.
 */
class CompanyDocumentController extends Controller
{
    public function __construct(
        private readonly ContractGenerationService $generationService,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * POST /api/companies/{company}/documents/generate
     */
    public function generate(GenerateDocumentRequest $request, Company $company): GenerateResultResource
    {
        $this->authorize('update', $company);

        $document = $this->resolveDocument($request, $company);

        $this->authorize('generate', $document);

        $result = $this->generationService->generate($document, $request->user()->id);

        return new GenerateResultResource($result['document'], $result['warnings']);
    }

    private function resolveDocument(GenerateDocumentRequest $request, Company $company): Document
    {
        // If a specific document_id was provided, use it.
        if ($request->filled('document_id')) {
            $doc = Document::query()->findOrFail((int) $request->input('document_id'));

            if ((int) $doc->source_company_id !== $company->id) {
                throw ValidationException::withMessages([
                    'document_id' => 'Указанный документ не относится к этой компании.',
                ])->status(422);
            }

            return $doc;
        }

        // Find an editable existing document for this company.
        $existing = Document::query()
            ->where('source_company_id', $company->id)
            ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::NeedsRework->value])
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Create a new document linked to this company.
        // product_code and country_code are required when creating a new document.
        if (! $request->filled('product_code') || ! $request->filled('country_code')) {
            throw ValidationException::withMessages([
                'product_code' => 'Укажите product_code и country_code для создания нового документа.',
            ])->status(422);
        }

        return $this->documentService->create([
            'kind' => 'contract',
            'product_code' => $request->input('product_code'),
            'country_code' => $request->input('country_code'),
            'city' => $request->input('city') ?? $company->city ?? 'Ташкент',
            'currency' => $request->input('currency') ?? 'UZS',
            'source_company_id' => $company->id,
        ], $request->user()->id);
    }
}
