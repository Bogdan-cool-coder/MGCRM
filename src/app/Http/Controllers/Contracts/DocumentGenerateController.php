<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\GenerateDocumentRequest;
use App\Http\Resources\Contracts\GenerateResultResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * DocumentGenerateController — handles generation and download for a known Document.
 *
 * POST /api/documents/{document}/generate    → synchronous generation (returns GenerateResultResource)
 * GET  /api/documents/{document}/download/docx → stream the generated DOCX file
 * GET  /api/documents/{document}/download/pdf  → stream the generated PDF file
 */
class DocumentGenerateController extends Controller
{
    public function __construct(
        private readonly ContractGenerationService $service,
    ) {}

    /**
     * POST /api/documents/{document}/generate
     *
     * Synchronously generates DOCX + PDF for the document.
     * Requires: template with uploaded docx, required custom fields filled.
     * Status remains draft after generation.
     */
    public function generate(GenerateDocumentRequest $request, Document $document): GenerateResultResource
    {
        $this->authorize('generate', $document);

        $result = $this->service->generate($document, $request->user()->id);

        return new GenerateResultResource($result['document'], $result['warnings']);
    }

    /**
     * GET /api/documents/{document}/download/docx
     *
     * Returns the generated DOCX file as an attachment.
     * 404 if not yet generated.
     */
    public function downloadDocx(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        if ($document->docx_path === null || ! Storage::disk('documents')->exists($document->docx_path)) {
            abort(404, 'DOCX file not found. Generate the document first.');
        }

        $filename = 'Договор '.($document->number ?? "#{$document->id}").'.docx';

        return response(
            Storage::disk('documents')->get($document->docx_path),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="'.rawurlencode($filename).'"',
            ],
        );
    }

    /**
     * GET /api/documents/{document}/download/pdf
     *
     * Returns the generated PDF file as an attachment.
     * 404 if not yet generated.
     */
    public function downloadPdf(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        if ($document->pdf_path === null || ! Storage::disk('documents')->exists($document->pdf_path)) {
            abort(404, 'PDF file not found. Generate the document first.');
        }

        $filename = 'Договор '.($document->number ?? "#{$document->id}").'.pdf';

        return response(
            Storage::disk('documents')->get($document->pdf_path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.rawurlencode($filename).'"',
            ],
        );
    }
}
