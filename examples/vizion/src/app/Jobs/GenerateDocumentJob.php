<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DocumentObjectDataResolver;
use App\Models\Company;
use App\Models\CompanyBranding;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Promotion;
use App\Services\Documents\DocumentDataAssembler;
use App\Services\Documents\DocxTemplateService;
use App\Services\Documents\GotenbergClient;
use App\Services\Documents\HtmlDocumentService;
use App\Services\MacroData\ConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Async renderer for a single GeneratedDocument (queue 'default').
 *
 * Lifecycle:
 *   1. DocumentController::generate() creates a GeneratedDocument(pending) and
 *      dispatches this job with its id.
 *   2. handle() flips status pending->processing, resolves the object data,
 *      builds the document (html OR docx branch), renders it to PDF via
 *      Gotenberg, stores the file(s) on the documents disk and flips status to
 *      done (pdf_path — and docx_path for the docx branch — set).
 *   3. Any exception sets status=error + the message into the `error` column.
 *
 * Object data is resolved through the DocumentObjectDataResolver contract — the
 * M2 binding swaps in the real MacroData implementation (which is why we
 * connect() before resolving).
 *
 * Two render branches:
 *   - html  (M1–M3): HtmlDocumentService::buildHtml() → Gotenberg htmlToPdf().
 *   - docx  (M5)    : DocxTemplateService::fill() over the uploaded template's
 *                     ${placeholders} → store the filled .docx → Gotenberg
 *                     officeToPdf() → store the .pdf. Both paths are persisted so
 *                     download?format=docx|pdf works.
 */
class GenerateDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $generatedDocumentId) {}

    /**
     * Shared render-only value assembler (discount.* / common.today / branding
     * tokens). Resolved in handle() and reused by the docx branch.
     */
    private DocumentDataAssembler $assembler;

    public function handle(
        ConnectionService $connection,
        DocumentObjectDataResolver $resolver,
        HtmlDocumentService $htmlService,
        GotenbergClient $gotenberg,
        DocxTemplateService $docxService,
        DocumentDataAssembler $assembler,
    ): void {
        $this->assembler = $assembler;

        $generated = GeneratedDocument::find($this->generatedDocumentId);

        if ($generated === null) {
            Log::warning('GenerateDocumentJob: generated document not found', [
                'generated_document_id' => $this->generatedDocumentId,
            ]);

            return;
        }

        // Idempotency guard: only run a pending row.
        if ($generated->status !== GeneratedDocument::STATUS_PENDING) {
            return;
        }

        $generated->update(['status' => GeneratedDocument::STATUS_PROCESSING]);

        try {
            $template = $generated->documentTemplate;
            $company = Company::findOrFail($generated->company_id);

            // Connect to the company's MacroData replica so the resolver can
            // read object fields. The stub resolver ignores the connection;
            // the M2 implementation relies on it.
            $connection->connect($company);

            $params = $generated->params ?? [];
            $estateSellId = (int) ($params['estate_sell_id'] ?? 0);

            $objectData = $estateSellId > 0
                ? $resolver->resolve($company, $estateSellId)
                : [];

            // Merge resolved object fields with any caller-supplied render params
            // (title, discount snapshot, ...) so both reach the template.
            $renderData = array_merge($params, $objectData);

            // BRANDING (M2): apply the company's brand profile (logo, palette,
            // fonts, header/footer). Absent branding falls back to defaults
            // inside the service — buildHtml never fails on a branding-less
            // company. Locale for header/footer comes from the params snapshot
            // (frontend passes it) or defaults to ru.
            $branding = $company->branding;
            $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'ru';

            // DISCOUNT (M3): apply the selected promotion. The controller already
            // validated company-ownership, active state and the discount range at
            // queue time; we re-load defensively and only apply the discount when
            // the promotion still belongs to this company and is active (it may
            // have been deactivated / deleted between enqueue and render). Absent
            // / invalid promotion → undiscounted КП, never a failure.
            [$promotion, $discount] = $this->resolvePromotion($params, $company);

            if ($template->type === 'docx') {
                $this->renderDocx($generated, $template, $renderData, $branding, $locale, $promotion, $discount, $docxService, $gotenberg);
            } else {
                $this->renderHtml($generated, $template, $renderData, $branding, $locale, $promotion, $discount, $htmlService, $gotenberg);
            }
        } catch (Throwable $e) {
            Log::error('GenerateDocumentJob failed', [
                'generated_document_id' => $generated->id,
                'message' => $e->getMessage(),
            ]);

            $generated->update([
                'status' => GeneratedDocument::STATUS_ERROR,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * HTML branch: build the branded HTML and render it to PDF via Gotenberg.
     *
     * @param  array<string, mixed>  $renderData
     */
    private function renderHtml(
        GeneratedDocument $generated,
        DocumentTemplate $template,
        array $renderData,
        ?CompanyBranding $branding,
        string $locale,
        ?Promotion $promotion,
        float $discount,
        HtmlDocumentService $htmlService,
        GotenbergClient $gotenberg,
    ): void {
        $html = $htmlService->buildHtml($template, $renderData, $branding, $locale, $promotion, $discount);
        $pdf = $gotenberg->htmlToPdf($html);

        $pdfPath = "documents/{$generated->id}/document.pdf";
        // Write on the dedicated "documents" disk (public visibility →
        // 0644 file / 0755 dir) so the file stays readable by php-fpm
        // (www-data) even though this job runs as root in the queue-worker
        // container.
        Storage::disk(config('documents.disk'))->put($pdfPath, $pdf);

        $generated->update([
            'status' => GeneratedDocument::STATUS_DONE,
            'pdf_path' => $pdfPath,
            'error' => null,
        ]);
    }

    /**
     * Word branch: fill the uploaded .docx template's ${placeholders} with flat
     * text values, store the filled .docx, convert it to PDF via Gotenberg
     * (LibreOffice) and store that too. Both paths are persisted so the download
     * endpoint can serve format=docx|pdf.
     *
     * The docx type carries no CSS — branding contributes text only
     * (header/footer/requisites), the discount numbers and the resolved object
     * fields. The same data-assembly intent as the html branch, flattened.
     *
     * @param  array<string, mixed>  $renderData
     */
    private function renderDocx(
        GeneratedDocument $generated,
        DocumentTemplate $template,
        array $renderData,
        ?CompanyBranding $branding,
        string $locale,
        ?Promotion $promotion,
        float $discount,
        DocxTemplateService $docxService,
        GotenbergClient $gotenberg,
    ): void {
        $sourcePath = $template->source_path;

        if (! is_string($sourcePath) || $sourcePath === '') {
            throw new RuntimeException('Docx template has no uploaded source file (source_path is empty).');
        }

        $disk = Storage::disk(config('documents.disk'));

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Docx source file is missing on disk: {$sourcePath}");
        }

        // Pull the source .docx to a local temp file so PHPWord (which works on
        // the filesystem, not the Flysystem abstraction) can open it.
        $localSource = $this->copyToTemp($disk->get($sourcePath), 'src');

        // Canonical data-map: object fields + discount.* + common.today + branding
        // text tokens (same definition the html branch uses, via the shared
        // assembler). The engine inside DocxTemplateService applies any per-token
        // filters (estate.price|words, deal.date|date_words, ...).
        $data = $this->assembler->assemble($renderData, $branding, $promotion, $discount, $locale);

        $config = $template->config ?? [];
        $fieldMapping = is_array($config['field_mapping'] ?? null) ? $config['field_mapping'] : [];

        try {
            $filledDocx = $docxService->fill($localSource, $data, $fieldMapping);

            // Read the filled .docx bytes. A false return (unreadable / vanished
            // temp file) must NOT be silently cast to "" and stored — that would
            // produce a 0-byte docx with a `done` status. Fail loudly so the job
            // lands in `error` instead of handing the user a corrupt file.
            $docxBytes = file_get_contents($filledDocx);
            if ($docxBytes === false) {
                throw new RuntimeException("Failed to read the filled docx file: {$filledDocx}");
            }

            // Persist the filled .docx and its PDF rendition on the documents disk.
            $docxPath = "documents/{$generated->id}/document.docx";
            $disk->put($docxPath, $docxBytes);

            $pdf = $gotenberg->officeToPdf($filledDocx);
            $pdfPath = "documents/{$generated->id}/document.pdf";
            $disk->put($pdfPath, $pdf);

            $generated->update([
                'status' => GeneratedDocument::STATUS_DONE,
                'docx_path' => $docxPath,
                'pdf_path' => $pdfPath,
                'error' => null,
            ]);

            @unlink($filledDocx);
        } finally {
            @unlink($localSource);
        }
    }

    /**
     * Re-load and validate the selected promotion at render time, returning
     * [promotion|null, discount]. A promotion that no longer belongs to the
     * company / is inactive (or no promotion at all) yields [null, 0.0].
     *
     * @param  array<string, mixed>  $params
     * @return array{0: ?Promotion, 1: float}
     */
    private function resolvePromotion(array $params, Company $company): array
    {
        $promotionId = (int) ($params['promotion_id'] ?? 0);
        $discount = (float) ($params['discount'] ?? 0);

        if ($promotionId <= 0) {
            return [null, 0.0];
        }

        $candidate = Promotion::find($promotionId);

        if ($candidate !== null
            && (int) $candidate->company_id === (int) $company->id
            && $candidate->is_active
        ) {
            return [$candidate, $discount];
        }

        return [null, 0.0];
    }

    /**
     * Write raw bytes to a uniquely-named local temp file and return its path.
     * Used to materialise a disk-stored .docx for PHPWord / Gotenberg.
     */
    private function copyToTemp(string $contents, string $hint): string
    {
        $path = tempnam(sys_get_temp_dir(), "vizion_docx_{$hint}_").'.docx';

        // A false return (no space / unwritable temp dir) must not be ignored —
        // a downstream PHPWord open would then fail far from the cause. Surface
        // the write failure here so the job errors with a clear message.
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Failed to write temp docx file: {$path}");
        }

        return $path;
    }
}
