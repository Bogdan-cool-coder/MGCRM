<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Contracts\Services\TerminationDocumentService;
use App\Domain\Crm\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreTerminationDocumentRequest;
use App\Http\Resources\Contracts\DocumentResource;
use App\Http\Resources\Contracts\GenerateResultResource;
use Illuminate\Http\JsonResponse;

/**
 * TerminationDocumentController — create and generate ДС о расторжении.
 *
 * POST /api/companies/{company}/termination-documents         → create draft ДС
 * POST /api/companies/{company}/termination-documents/generate → create + generate docx/pdf
 */
class TerminationDocumentController extends Controller
{
    public function __construct(
        private readonly TerminationDocumentService $terminationService,
        private readonly ContractGenerationService $generationService,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * POST /api/companies/{company}/termination-documents
     *
     * Creates a draft TerminationAgreement Document pinned to the company's
     * current requisites. Returns the new Document resource (status=draft).
     */
    public function store(StoreTerminationDocumentRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $doc = $this->terminationService->create(
            $company,
            $request->validated(),
            $request->user()->id,
        );

        return (new DocumentResource($doc))->response()->setStatusCode(201);
    }

    /**
     * POST /api/companies/{company}/termination-documents/generate
     *
     * Creates a draft TerminationAgreement Document and immediately generates
     * the DOCX + PDF via ContractGenerationService. Returns GenerateResultResource.
     *
     * This endpoint is the "one-shot" path for the UI: create + generate in
     * one HTTP call so the operator does not need two trips.
     */
    public function generate(StoreTerminationDocumentRequest $request, Company $company): GenerateResultResource
    {
        $this->authorize('update', $company);

        $doc = $this->terminationService->create(
            $company,
            $request->validated(),
            $request->user()->id,
        );

        $this->authorize('generate', $doc);

        $result = $this->generationService->generate($doc, $request->user()->id);

        return new GenerateResultResource($result['document'], $result['warnings']);
    }
}
