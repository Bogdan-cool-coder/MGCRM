<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Events\TerminationAgreementSigned;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Contracts\Models\Template;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * DocumentService — CRUD + state-machine transitions for Document.
 *
 * Rules:
 * - Status transitions ONLY via transition(). Never set $doc->status directly elsewhere.
 * - Money fields (subtotal, discount_amount, total) are always integers (kopecks).
 * - Revision snapshots are created inside the submit transition (DB::transaction).
 * - The uploaded stub throws 409; real upload is M11.
 */
class DocumentService
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly EntityLogService $entityLog,
        private readonly DealService $dealService,
    ) {}

    /**
     * Paginated list with optional filters.
     *
     * Visibility scoping (ARCHITECTURE.md §3), gated by the `contracts.view-all`
     * permission (spatie, sanctum guard — granted to admin / lawyer / director):
     *   - has contracts.view-all → all documents (full visibility / oversight)
     *   - otherwise              → own documents only (author_user_id = caller)
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25, ?User $caller = null): LengthAwarePaginator
    {
        $query = Document::query()
            ->with(['author:id,full_name', 'sourceCompany:id,name', 'templateVersion.template:id,code']);

        // Author-scoping (IAM-2): callers without `contracts.view-all` see only
        // their own documents. The permission is granted to admin / lawyer /
        // director (full oversight) — identical to the prior inline role list,
        // now resolved through spatie on the sanctum guard.
        if ($caller !== null && ! $caller->can('contracts.view-all')) {
            $query->where('author_user_id', $caller->id);
        }

        // By default hide archived records (archived=0 or absent).
        $showArchived = filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $showArchived) {
            $query->whereNull('archived_at');
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (isset($filters['product_code'])) {
            $query->where('product_code', $filters['product_code']);
        }

        if (isset($filters['country_code'])) {
            $query->where('country_code', $filters['country_code']);
        }

        if (isset($filters['author_id'])) {
            $query->where('author_user_id', $filters['author_id']);
        }

        if (isset($filters['deal_id'])) {
            $query->where('source_deal_id', (int) $filters['deal_id']);
        }

        if (isset($filters['source_company_id'])) {
            $query->where('source_company_id', (int) $filters['source_company_id']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Create a new Document with status=Draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $authorUserId): Document
    {
        $doc = Document::create([
            'kind' => $data['kind'] ?? 'contract',
            'product_code' => $data['product_code'],
            'country_code' => $data['country_code'],
            'city' => $data['city'] ?? null,
            'title' => $data['title'] ?? null,
            'currency' => $data['currency'] ?? null,
            'source_deal_id' => $data['source_deal_id'] ?? null,
            'source_company_id' => $data['source_company_id'] ?? null,
            'author_user_id' => $authorUserId,
            'status' => ContractStatus::Draft->value,
            'context' => $data['context'] ?? [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'extra_fields' => $data['extra_fields'] ?? [],
            'subtotal' => 0,
            'discount_pct' => 0,
            'discount_amount' => 0,
            'total' => 0,
        ]);

        return $doc;
    }

    /**
     * Update mutable fields. Forbidden when status is not Draft or NeedsRework,
     * EXCEPT for `signed_at` which can be recorded on any status (the author records
     * the factual date the physical contract was signed after uploading the scan).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $doc, array $data): Document
    {
        // `signed_at` is always patchable regardless of status.
        // Extract it, apply it separately, then proceed with the editable-only fields.
        $signedAt = array_key_exists('signed_at', $data) ? $data['signed_at'] : false;
        $editableData = array_diff_key($data, ['signed_at' => true]);

        if ($editableData !== []) {
            $this->assertEditable($doc);

            // Merge context if provided (patch semantics).
            if (isset($editableData['context']) && is_array($editableData['context'])) {
                $merged = array_merge((array) ($doc->context ?? []), $editableData['context']);
                $editableData['context'] = $merged;
            }

            // Recalculate discount_amount and total if discount_pct changes.
            if (isset($editableData['discount_pct'])) {
                $subtotal = $doc->subtotal;
                $pct = (float) $editableData['discount_pct'];
                $discountAmount = (int) round($subtotal * $pct / 100);
                $editableData['discount_amount'] = $discountAmount;
                $editableData['total'] = $subtotal - $discountAmount;
            }

            $doc->update($editableData);
        }

        // Persist signed_at factual date (unconditional when provided in the PATCH payload).
        if ($signedAt !== false) {
            $doc->signed_at = $signedAt !== null ? Carbon::parse($signedAt) : null;
            $doc->save();
        }

        $doc->refresh();

        return $doc;
    }

    /**
     * Add a product line item to a Document.
     * Snapshots name and unit_price from the catalog at creation time.
     * Recalculates subtotal / discount_amount / total on the parent Document.
     *
     * @param  array<string, mixed>  $data  {product_id, plan_id?, qty?, sort_order?}
     */
    public function addItem(Document $doc, array $data): DocumentItem
    {
        $this->assertEditable($doc);

        return DB::transaction(function () use ($doc, $data): DocumentItem {
            $product = Product::query()->findOrFail($data['product_id']);
            $qty = isset($data['qty']) ? (float) $data['qty'] : 1.0;

            // Snapshot: look up the unit price from the catalog.
            // If a plan is provided, try to find its price; otherwise use the
            // product default price for the document's currency.
            $unitPrice = $this->resolveUnitPrice($product, $data['plan_id'] ?? null, $doc->currency);
            $lineTotal = (int) round($qty * $unitPrice);

            $item = DocumentItem::create([
                'document_id' => $doc->id,
                'product_id' => $product->id,
                'plan_id' => $data['plan_id'] ?? null,
                'name_snapshot' => $product->name,
                'currency' => $doc->currency ?? 'RUB',
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->recalcTotals($doc);

            return $item->fresh();
        });
    }

    /**
     * Update a DocumentItem's qty or sort_order. Recalculates line_total and
     * Document totals.
     *
     * @param  array<string, mixed>  $data  {qty?, sort_order?}
     */
    public function updateItem(Document $doc, DocumentItem $item, array $data): DocumentItem
    {
        $this->assertEditable($doc);

        return DB::transaction(function () use ($doc, $item, $data): DocumentItem {
            if (isset($data['qty'])) {
                $qty = (float) $data['qty'];
                $item->qty = $qty;
                $item->line_total = (int) round($qty * $item->unit_price);
            }

            if (isset($data['sort_order'])) {
                $item->sort_order = (int) $data['sort_order'];
            }

            $item->save();
            $this->recalcTotals($doc);

            return $item->fresh();
        });
    }

    /**
     * Delete a DocumentItem and recalculate Document totals.
     */
    public function deleteItem(Document $doc, DocumentItem $item): void
    {
        $this->assertEditable($doc);

        DB::transaction(function () use ($doc, $item): void {
            $item->delete();
            $this->recalcTotals($doc);
        });
    }

    /**
     * Execute a status transition on a Document.
     *
     * Uses lockForUpdate to prevent concurrent double-transitions.
     * Creates side effects (revision snapshot, timestamps) within the transaction.
     *
     * @throws ValidationException when the transition is not allowed by the enum guard
     * @throws HttpException 409 when transitioning to Uploaded (M11 stub)
     */
    public function transition(Document $doc, ContractStatus $to, int $userId, ?string $note = null): Document
    {
        DB::transaction(function () use ($doc, $to, $userId, $note): void {
            $locked = Document::query()->lockForUpdate()->findOrFail($doc->id);

            if (! $locked->status->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transition from {$locked->status->value} to {$to->value}.",
                ])->status(422);
            }

            // Per-transition guards
            match ($to) {
                ContractStatus::Uploaded => throw new HttpException(409, 'not_yet_implemented'),
                ContractStatus::Submitted => $this->guardSubmit($locked, $userId),
                ContractStatus::Signed => $this->guardSign($locked),
                default => null,
            };

            $from = $locked->status;
            $locked->status = $to;

            // Side effects
            $this->applySideEffects($locked, $from, $to, $userId, $note);

            $locked->save();

            // Sync the in-memory model so $doc->fresh() returns current state.
            $doc->status = $to;
        });

        // Polymorphic action log: a contract lifecycle transition is recorded on
        // the document's source subject(s). This is the Contracts extension point
        // for the entity log — it fires only when a deal/company source is linked
        // (a free-standing document simply produces no entity-log row).
        $this->recordContractEvent($doc, $to, $userId, $note);

        // Post-commit event for TerminationAgreement → Signed.
        // Fired outside the transaction so the signed_at row is already persisted.
        if ($to === ContractStatus::Signed) {
            $this->dispatchTerminationSignedIfNeeded($doc->fresh());
        }

        return $doc->fresh();
    }

    /**
     * Record a contract_event on the document's source deal and/or company. Soft
     * by design: when neither a source deal nor a source company is linked, no
     * log row is written (no subject to attribute it to). The action vocabulary
     * is shared (LogAction::ContractEvent); the document status lives in meta so
     * the deal/company card can read the contract lifecycle without coupling to
     * the Contracts tables.
     */
    private function recordContractEvent(Document $doc, ContractStatus $to, int $userId, ?string $note): void
    {
        $actor = User::find($userId);

        $meta = [
            'document_id' => (int) $doc->id,
            'number' => $doc->number,
            'kind' => $doc->kind instanceof \BackedEnum ? $doc->kind->value : $doc->kind,
            'status' => $to->value,
            'note' => $note,
        ];

        if ($doc->source_deal_id !== null) {
            $this->entityLog->record(
                LogSubjectType::Deal,
                (int) $doc->source_deal_id,
                $actor,
                LogAction::ContractEvent,
                $meta,
            );

            // Auto key action: a contract Document entering `submitted` (sent into
            // the flow) marks the source deal's contract as sent — idempotent
            // (first send only, never clobbers a manually-entered date). Other
            // document kinds (invoice/act/...) do not feed this signal.
            $docKind = $doc->kind instanceof DocumentKind ? $doc->kind : DocumentKind::tryFrom((string) $doc->kind);
            if ($docKind === DocumentKind::Contract && $to === ContractStatus::Submitted) {
                $this->dealService->markContractSentFromDocument((int) $doc->source_deal_id, $actor);
            }
        }

        if ($doc->source_company_id !== null) {
            $this->entityLog->record(
                LogSubjectType::Company,
                (int) $doc->source_company_id,
                $actor,
                LogAction::ContractEvent,
                $meta,
            );
        }
    }

    /**
     * Stub for the Google Drive upload endpoint (M11).
     * Always throws 409 not_yet_implemented.
     *
     * @throws HttpException 409
     */
    public function stubUploadDrive(): never
    {
        throw new HttpException(409, 'not_yet_implemented');
    }

    /**
     * Cross-domain entry point for Automation M7 action `generate_document`.
     *
     * Resolves (or creates) a Document linked to the given Deal, looks up the
     * Template by its unique code, then delegates full generation (PHPWord +
     * Gotenberg) to ContractGenerationService::generate().
     *
     * ContractGenerationService is passed in to avoid a circular DI chain
     * (ContractGenerationService already depends on DocumentService).
     *
     * @param  array<string, mixed>  $opts  Optional overrides: product_code, country_code, city, currency.
     *
     * @throws ValidationException 422 on business-rule violations (template not found, city missing, …)
     * @throws HttpException 502/503 on Gotenberg failures
     */
    public function generateByTemplateCode(
        Deal $deal,
        string $templateCode,
        ContractGenerationService $generationService,
        array $opts = [],
        int $actorUserId = 0,
    ): Document {
        // 1. Validate template exists by code (raises ModelNotFoundException → 404 upstream).
        Template::where('code', $templateCode)->firstOrFail();

        // 2. Resolve or create an editable Document for this deal.
        $doc = Document::query()
            ->where('source_deal_id', $deal->id)
            ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::NeedsRework->value])
            ->orderByDesc('created_at')
            ->first();

        if ($doc === null) {
            $doc = $this->create([
                'kind' => 'contract',
                'product_code' => $opts['product_code'] ?? 'macrocrm',
                'country_code' => $opts['country_code'] ?? 'uz',
                'city' => $opts['city'] ?? $deal->company?->city ?? 'Ташкент',
                'currency' => $opts['currency'] ?? $deal->currency ?? 'UZS',
                'source_deal_id' => $deal->id,
                'source_company_id' => $deal->company_id,
            ], $actorUserId);
        }

        // 3. Generate DOCX + PDF.
        $result = $generationService->generate($doc, $actorUserId);

        return $result['document'];
    }

    /**
     * Cross-domain entry point for the Sales won-gate (S2.8). The ONLY way Sales
     * reads the documents table — Sales never queries Document directly.
     *
     * True when the deal has a "live" contract: a Document with this source_deal_id
     * whose status is approved / signed / uploaded. draft / submitted / in_review /
     * needs_rework and the terminal rejected / archived do NOT count.
     */
    public function hasActiveContractForDeal(int $dealId): bool
    {
        return Document::query()
            ->where('source_deal_id', $dealId)
            ->whereIn('status', [
                ContractStatus::Approved->value,
                ContractStatus::Signed->value,
                ContractStatus::Uploaded->value,
            ])
            ->whereNotNull('docx_path')  // Guard: only real generated+approved docs satisfy the gate
            ->exists();
    }

    /**
     * Count the documents generated from a deal — the «документов» figure on the
     * deal-card «Активность» metrics block. Cross-domain read entry point for the
     * Sales domain (it never queries the documents table directly, DDD §2),
     * parallel to hasActiveContractForDeal(). Counts every Document with this
     * source_deal_id regardless of status (the timeline total, not just live ones).
     */
    public function countForDeal(int $dealId): int
    {
        return Document::query()
            ->where('source_deal_id', $dealId)
            ->count();
    }

    /**
     * Record generated file paths on a Document (called by S2.4 GenerationService).
     */
    public function recordGenerated(Document $doc, string $docxPath, string $pdfPath, ?int $templateVersionId): Document
    {
        $doc->update([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'template_version' => $templateVersionId,
        ]);

        return $doc->fresh();
    }

    // ---- Private helpers ----

    private function assertEditable(Document $doc): void
    {
        if (! in_array($doc->status, [ContractStatus::Draft, ContractStatus::NeedsRework], strict: true)) {
            throw ValidationException::withMessages([
                'status' => "Document can only be edited in Draft or NeedsRework status (current: {$doc->status->value}).",
            ])->status(422);
        }
    }

    private function guardSubmit(Document $locked, int $userId): void
    {
        if ($locked->archived_at !== null) {
            throw ValidationException::withMessages([
                'status' => 'Cannot submit an archived document.',
            ])->status(422);
        }
    }

    /**
     * Apply status-transition side effects BEFORE save().
     */
    private function applySideEffects(
        Document $locked,
        ContractStatus $from,
        ContractStatus $to,
        int $userId,
        ?string $note = null,
    ): void {
        match ($to) {
            ContractStatus::Submitted => $this->createRevisionSnapshot($locked, $userId, $note),
            ContractStatus::Signed => $this->applySigned($locked),
            ContractStatus::Archived => $locked->archived_at = now(),
            default => null,
        };
    }

    /**
     * Create an immutable DocumentRevision snapshot.
     * version_number is max(existing) + 1; attempt is also incremented.
     */
    private function createRevisionSnapshot(Document $locked, int $userId, ?string $note): void
    {
        $lastRevision = DocumentRevision::query()
            ->where('document_id', $locked->id)
            ->orderByDesc('version_number')
            ->first();

        $versionNumber = $lastRevision ? $lastRevision->version_number + 1 : 1;
        $attempt = $lastRevision ? $lastRevision->attempt + 1 : 1;

        DocumentRevision::create([
            'document_id' => $locked->id,
            'version_number' => $versionNumber,
            'attempt' => $attempt,
            'context_snapshot' => $locked->context ?? [],
            'template_version' => $locked->template_version,
            'docx_path' => $locked->docx_path,
            'pdf_path' => $locked->pdf_path,
            'note' => $note ?? 'Submitted for approval',
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Recalculate subtotal, discount_amount and total from current items.
     */
    private function recalcTotals(Document $doc): void
    {
        $subtotal = (int) DocumentItem::query()
            ->where('document_id', $doc->id)
            ->sum('line_total');

        $pct = (float) $doc->discount_pct;
        $discountAmount = (int) round($subtotal * $pct / 100);
        $total = $subtotal - $discountAmount;

        $doc->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $total,
        ]);
    }

    /**
     * Archive a document (set archived_at = now()).
     * Archive is a flag, NOT a status transition — status remains unchanged.
     *
     * @throws ValidationException when document is in InReview status
     */
    public function archive(Document $doc, int $userId): Document
    {
        if ($doc->status === ContractStatus::InReview) {
            throw ValidationException::withMessages([
                'status' => 'Cannot archive a document that is currently in review.',
            ])->status(422);
        }

        $doc->archived_at = now();
        $doc->save();

        return $doc->fresh();
    }

    /**
     * Unarchive a document (clear archived_at).
     */
    public function unarchive(Document $doc, int $userId): Document
    {
        $doc->archived_at = null;
        $doc->save();

        return $doc->fresh();
    }

    /**
     * Unsign a document: Signed → Approved, signed_at = null.
     * Only admin/lawyer (enforced by Policy).
     *
     * @throws ValidationException when document is not in Signed status
     */
    public function unsign(Document $doc, int $userId): Document
    {
        if ($doc->status !== ContractStatus::Signed) {
            throw ValidationException::withMessages([
                'status' => "Document must be in 'signed' status to unsign (current: {$doc->status->value}).",
            ])->status(422);
        }

        $doc->status = ContractStatus::Approved;
        $doc->signed_at = null;
        $doc->save();

        return $doc->fresh();
    }

    // ---- Private helpers ----

    /**
     * Guard: Approved → Signed requires at least one signed_scan attachment.
     *
     * @throws ValidationException 422 when signed_scan is missing
     */
    private function guardSign(Document $locked): void
    {
        if (! $this->attachmentService->hasSignedScan($locked)) {
            throw ValidationException::withMessages([
                'attachments' => 'Upload a signed scan (kind=signed_scan) before signing.',
            ])->status(422);
        }
    }

    /**
     * Side effect for Approved → Signed transition.
     *
     * For termination_agreement documents, also dispatches TerminationAgreementSigned
     * so N6-crm can update the company's disconnect state.
     * The event is dispatched AFTER this method returns (outside the transaction
     * lock) to ensure the row is committed before any listener reads it.
     * We store a flag on the locked model so transition() can fire it post-commit.
     */
    private function applySigned(Document $locked): void
    {
        $locked->signed_at = now();
        // TODO(cs-specialist): trigger CS subscription creation after signed (S-CS sprint)
    }

    /**
     * Dispatch TerminationAgreementSigned after the transaction commits.
     * Called from transition() when kind=termination_agreement moves to Signed.
     */
    private function dispatchTerminationSignedIfNeeded(Document $doc): void
    {
        $kind = $doc->kind instanceof DocumentKind ? $doc->kind : DocumentKind::tryFrom((string) $doc->kind);

        if ($kind === DocumentKind::TerminationAgreement && $doc->source_company_id !== null) {
            event(TerminationAgreementSigned::fromDocument($doc));
        }
    }

    /**
     * Resolve the unit price (in kopecks) for a product / optional plan.
     * Falls back to 0 if no price is found (price setup happens in catalog).
     */
    private function resolveUnitPrice(Product $product, ?int $planId, ?string $currency): int
    {
        // Try catalog price for this currency.
        if ($currency !== null) {
            $q = $product->prices()->where('currency_code', $currency);

            if ($planId !== null) {
                $q->where('plan_id', $planId);
            }

            $priceRecord = $q->orderByDesc('valid_from')->first();

            if ($priceRecord !== null) {
                return (int) $priceRecord->amount;
            }
        }

        // No price found — defaults to 0 (can be set manually on the item later).
        return 0;
    }
}
