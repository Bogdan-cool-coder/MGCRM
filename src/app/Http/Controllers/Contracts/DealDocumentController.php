<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Sales\Models\Deal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\GenerateDocumentRequest;
use App\Http\Resources\Contracts\GenerateResultResource;
use Illuminate\Validation\ValidationException;

/**
 * DealDocumentController — generate a Document from a Deal context.
 *
 * POST /api/deals/{deal}/documents/generate
 *
 * Resolves or creates a Document linked to the deal, then delegates to
 * ContractGenerationService::generate().
 *
 * If document_id is passed in body: use that document (must belong to deal).
 * Otherwise: find the first draft/needs_rework document for the deal, or create one.
 */
class DealDocumentController extends Controller
{
    public function __construct(
        private readonly ContractGenerationService $generationService,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * POST /api/deals/{deal}/documents/generate
     */
    public function generate(GenerateDocumentRequest $request, Deal $deal): GenerateResultResource
    {
        $this->authorize('update', $deal);

        $document = $this->resolveDocument($request, $deal);

        $this->authorize('generate', $document);

        $templateId = $request->integer('template_id') ?: null;
        $result = $this->generationService->generate($document, $request->user()->id, $templateId);

        return new GenerateResultResource($result['document'], $result['warnings']);
    }

    private function resolveDocument(GenerateDocumentRequest $request, Deal $deal): Document
    {
        // If a specific document_id was provided, use it.
        if ($request->filled('document_id')) {
            $doc = Document::query()->findOrFail((int) $request->input('document_id'));

            if ((int) $doc->source_deal_id !== $deal->id) {
                throw ValidationException::withMessages([
                    'document_id' => 'Указанный документ не относится к этой сделке.',
                ])->status(422);
            }

            return $doc;
        }

        // Find an editable existing document for this deal.
        $existing = Document::query()
            ->where('source_deal_id', $deal->id)
            ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::NeedsRework->value])
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Create a new document linked to this deal.
        $productCode = $request->input('product_code') ?? 'macrocrm';
        $countryCode = $request->input('country_code') ?? 'uz';
        $city = $request->input('city') ?? $deal->company?->city ?? 'Ташкент';

        return $this->documentService->create([
            'kind' => 'contract',
            'product_code' => $productCode,
            'country_code' => $countryCode,
            'city' => $city,
            'currency' => $request->input('currency') ?? 'UZS',
            'source_deal_id' => $deal->id,
            'source_company_id' => $deal->company_id,
        ], $request->user()->id);
    }
}
