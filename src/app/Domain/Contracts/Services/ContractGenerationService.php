<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Services\Helpers\MoneyFormatter;
use App\Support\Documents\GotenbergClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ContractGenerationService — orchestrates DOCX + PDF generation for a Document.
 *
 * Pipeline:
 *   1. Lock Document row (concurrency guard)
 *   2. Validate: docx version exists, required custom variables filled
 *   3. Reserve contract number if not yet assigned (ContractNumberingService)
 *   4. Build flat context (ContractContextBuilder)
 *   5. PHPWord TemplateProcessor render → temp docx
 *   6. Gotenberg officeToPdf → raw PDF bytes
 *   7. Save docx + pdf to disk 'documents'
 *   8. DocumentService::recordGenerated (update paths)
 *   9. Create DocumentRevision snapshot
 *  10. Return updated Document + warnings[]
 *
 * Status stays 'draft' throughout.
 *
 * Sync execution — ~5–15 seconds, fits within HTTP timeout.
 * GenerateContractJob wraps this for future bulk dispatch (not used from HTTP).
 */
class ContractGenerationService
{
    public function __construct(
        private readonly TemplateService $templateService,
        private readonly ContractContextBuilder $contextBuilder,
        private readonly ContractNumberingService $numberingService,
        private readonly DocumentService $documentService,
        private readonly GotenbergClient $gotenbergClient,
    ) {}

    /**
     * Generate DOCX + PDF for the given Document.
     *
     * @param  int|null  $templateId  Optional explicit Template ID from the UI picker.
     *                                When provided, the chosen template is used instead
     *                                of the kind-based fallback to master_skeleton.
     * @return array{document: Document, warnings: list<string>}
     *
     * @throws ValidationException 422 on business rule violations
     * @throws HttpException 503/502 on Gotenberg failures
     */
    public function generate(Document $doc, int $userId, ?int $templateId = null): array
    {
        // Guard: only draft or needs_rework can be (re)generated.
        if (! in_array($doc->status, [ContractStatus::Draft, ContractStatus::NeedsRework], strict: true)) {
            throw ValidationException::withMessages([
                'status' => "Генерация невозможна в статусе {$doc->status->value}",
            ])->status(422);
        }

        // Guard: city must be set for numbering.
        if (empty($doc->city)) {
            throw ValidationException::withMessages([
                'city' => 'Укажите город для документа (поле city)',
            ])->status(422);
        }

        // 1. Resolve template:
        //    - Explicit template_id from request (UI picker) takes highest priority.
        //    - If Document carries a pinned template_version FK, use that version's template.
        //    - Otherwise fall back to master_skeleton (default for kind=contract).
        //    This lets termination_agreement docs use a dedicated template without
        //    touching the master_skeleton flow.
        $resolvedTemplate = $this->resolveTemplate($doc, $templateId);

        $masterTemplate = $resolvedTemplate;

        // This throws RuntimeException with a clear message if no docx uploaded.
        try {
            $docxTemplatePath = $this->templateService->getDocxPath($masterTemplate);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'template' => 'Шаблон не загружен. Загрузите docx-файл через /api/templates/{id}/upload',
            ])->status(422);
        }

        // Absolute path on the documents disk.
        $templateAbsPath = Storage::disk('documents')->path($docxTemplatePath);
        if (! is_file($templateAbsPath)) {
            throw ValidationException::withMessages([
                'template' => 'Шаблон не загружен. Загрузите docx-файл через /api/templates/{id}/upload',
            ])->status(422);
        }

        // 2. Collect warnings (ai_check, not a blocker).
        $warnings = [];
        $currentVersion = $masterTemplate->currentVersion;
        if ($currentVersion !== null && ($currentVersion->pdf_ok === false || $currentVersion->pdf_ok === null)) {
            $warnings[] = 'template_not_checked';
        }

        // 3. Reserve number (lock Document for concurrency safety).
        DB::transaction(function () use ($doc): void {
            $locked = Document::query()->lockForUpdate()->findOrFail($doc->id);

            if ($locked->number === null) {
                $result = $this->numberingService->nextNumber($locked->city, $locked->country_code);

                $locked->number = $result['number'];
                $locked->city_code = $result['city_code'];
                $locked->save();

                // Sync in-memory object.
                $doc->number = $locked->number;
                $doc->city_code = $locked->city_code;
            }
        });

        // 4. Build context (validates required custom variables — may throw 422).
        $flatContext = $this->contextBuilder->build($doc);

        // 5. Render DOCX via PHPWord TemplateProcessor.
        $tempDocxPath = sys_get_temp_dir().'/mgcrm_contract_'.$doc->id.'_'.uniqid().'.docx';

        try {
            $processor = new TemplateProcessor($templateAbsPath);

            // Set all flat key→value substitutions.
            foreach ($flatContext as $key => $value) {
                try {
                    $processor->setValue($key, htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
                } catch (\Throwable) {
                    // Key not present in template — silently skip.
                }
            }

            // cloneRow for items table.
            $this->renderItemsTable($processor, $doc);

            // cloneRow for payments table.
            $this->renderPaymentsTable($processor, $doc);

            $processor->saveAs($tempDocxPath);
        } catch (\Throwable $e) {
            @unlink($tempDocxPath);
            throw new HttpException(500, 'Ошибка рендера DOCX: '.$e->getMessage(), $e);
        }

        // 6. Convert DOCX → PDF via Gotenberg.
        // Read docx bytes before cleanup so we can save both files to disk.
        $docxContents = file_get_contents($tempDocxPath) ?: '';

        try {
            $pdfBytes = $this->gotenbergClient->officeToPdf($tempDocxPath);
        } catch (ConnectionException $e) {
            Log::error('Gotenberg connection failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            @unlink($tempDocxPath);
            throw new HttpException(503, 'Сервис генерации PDF временно недоступен');
        } catch (RuntimeException $e) {
            Log::error('Gotenberg error', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            @unlink($tempDocxPath);
            throw new HttpException(502, 'Ошибка конвертации в PDF: '.$e->getMessage());
        }

        @unlink($tempDocxPath);

        // 7. Save files to disk 'documents'.
        $docxDiskPath = "contracts/{$doc->id}/contract.docx";
        $pdfDiskPath = "contracts/{$doc->id}/contract.pdf";

        Storage::disk('documents')->put($docxDiskPath, $docxContents);
        Storage::disk('documents')->put($pdfDiskPath, $pdfBytes);

        // 8. Update Document paths + template_version via DocumentService.
        $templateVersionId = $currentVersion?->id;
        $doc = $this->documentService->recordGenerated($doc, $docxDiskPath, $pdfDiskPath, $templateVersionId);

        // 9. Create DocumentRevision snapshot.
        $this->createRevision($doc, $userId, $flatContext);

        return ['document' => $doc, 'warnings' => $warnings];
    }

    // ---- Private helpers ----

    /**
     * Resolve the Template to use for generation.
     *
     * Priority (highest → lowest):
     *  1. Explicit $templateId passed from the UI picker (most specific — user intent).
     *  2. Explicit template_code stored in Document.context['template_code'] (future use).
     *  3. Document.kind-based code:
     *       termination_agreement → Template(code='termination_agreement')
     *       contract (default)    → Template(code='master_skeleton')
     *
     * Falls back to master_skeleton when the kind-specific template is not found
     * so existing contract generation keeps working without disruption.
     *
     * @throws ValidationException 422 when $templateId is provided but the template does not exist.
     */
    private function resolveTemplate(Document $doc, ?int $templateId = null): Template
    {
        // 1. Explicit template from the UI picker.
        if ($templateId !== null) {
            $t = Template::with('currentVersion')->find($templateId);
            if ($t === null) {
                throw ValidationException::withMessages([
                    'template_id' => "Шаблон #{$templateId} не найден.",
                ])->status(422);
            }

            return $t;
        }

        // 2. Explicit override stored in context (future use, no breaking change).
        $explicitCode = (string) ($doc->context['template_code'] ?? '');
        if ($explicitCode !== '') {
            $t = Template::where('code', $explicitCode)->with('currentVersion')->first();
            if ($t !== null) {
                return $t;
            }
        }

        // 3. Kind-based resolution.
        $kindCode = match ($doc->kind) {
            DocumentKind::TerminationAgreement => 'termination_agreement',
            default => 'master_skeleton',
        };

        $t = Template::where('code', $kindCode)->with('currentVersion')->first();

        // Fallback: always return master_skeleton so contract generation never breaks.
        if ($t === null && $kindCode !== 'master_skeleton') {
            $t = Template::where('code', 'master_skeleton')->with('currentVersion')->firstOrFail();
        }

        return $t ?? Template::where('code', 'master_skeleton')->with('currentVersion')->firstOrFail();
    }

    /**
     * Clone and fill the items table rows (${item_name}, etc.).
     */
    private function renderItemsTable(TemplateProcessor $processor, Document $doc): void
    {
        $itemRows = $this->contextBuilder->buildItemRows($doc);

        if (empty($itemRows)) {
            return;
        }

        // Check if the template has the marker key before cloneRow.
        $vars = $processor->getVariables();
        if (! in_array('item_name', $vars, strict: true)) {
            return;
        }

        $processor->cloneRow('item_name', count($itemRows));

        foreach ($itemRows as $i => $row) {
            $n = $i + 1;
            $processor->setValue("item_name#{$n}", htmlspecialchars($row['item_name'], ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $processor->setValue("item_qty#{$n}", htmlspecialchars($row['item_qty'], ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $processor->setValue("item_price#{$n}", htmlspecialchars($row['item_price'], ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $processor->setValue("item_total#{$n}", htmlspecialchars($row['item_total'], ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
    }

    /**
     * Clone and fill the payments table rows (${payment_date}, ${payment_amount}).
     */
    private function renderPaymentsTable(TemplateProcessor $processor, Document $doc): void
    {
        $payments = (array) ($doc->context['payments'] ?? []);

        if (empty($payments)) {
            return;
        }

        $vars = $processor->getVariables();
        if (! in_array('payment_date', $vars, strict: true)) {
            return;
        }

        $processor->cloneRow('payment_date', count($payments));

        foreach ($payments as $i => $payment) {
            $n = $i + 1;
            $date = MoneyFormatter::formatDateRu($payment['date'] ?? null);
            $amount = MoneyFormatter::format(
                (int) ($payment['amount'] ?? 0),
                $doc->currency ?? 'RUB'
            );
            $processor->setValue("payment_date#{$n}", htmlspecialchars($date, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
            $processor->setValue("payment_amount#{$n}", htmlspecialchars($amount, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
    }

    /**
     * Create a DocumentRevision snapshot for this generation attempt.
     *
     * Generation increments version_number only — NOT attempt.
     * attempt is incremented exclusively by DocumentService::createRevisionSnapshot()
     * (called on submit). This keeps attempt aligned with submission rounds (1, 2, 3…)
     * regardless of how many times the doc is regenerated per round.
     */
    private function createRevision(Document $doc, int $userId, array $flatContext): void
    {
        $lastRevision = DocumentRevision::query()
            ->where('document_id', $doc->id)
            ->orderByDesc('version_number')
            ->first();

        $versionNumber = $lastRevision ? $lastRevision->version_number + 1 : 1;
        // Re-use the current attempt value from the last revision (or 0 for the
        // first generation before any submit). Submit will bump it to 1 at send time.
        $attempt = $lastRevision ? (int) $lastRevision->attempt : 0;

        DocumentRevision::create([
            'document_id' => $doc->id,
            'version_number' => $versionNumber,
            'attempt' => $attempt,
            'context_snapshot' => $doc->context ?? [],
            'template_version' => $doc->template_version,
            'docx_path' => $doc->docx_path,
            'pdf_path' => $doc->pdf_path,
            'note' => 'Generated',
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }
}
