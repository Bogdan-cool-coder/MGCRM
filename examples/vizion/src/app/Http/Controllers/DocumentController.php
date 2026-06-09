<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DocumentObjectDataResolver;
use App\Http\Controllers\Concerns\AssertsConfigEntityReadAccess;
use App\Jobs\GenerateDocumentJob;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Promotion;
use App\Services\Documents\DocxTemplateService;
use App\Services\Documents\HtmlDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * DocumentController — CRUD for document templates (PDF/Word blueprints).
 * Structural mirror of WidgetController: identical visibility model (system /
 * published / personal), identical role-gated write rules, dry-run-failed hiding
 * on index.
 *
 * Reads are gated through the generic AssertsConfigEntityReadAccess trait
 * (shared with Report / Widget / Dashboard). Writes are enforced inline (owner or
 * admin/superadmin of the active company; superadmin cross-company).
 *
 * Generation (POST /documents/{id}/generate) is async via GenerateDocumentJob;
 * the resulting file is fetched through the generated/{id} status + download
 * endpoints.
 */
class DocumentController extends Controller
{
    use AssertsConfigEntityReadAccess;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Single source of truth: ResolveActiveCompany middleware. We never
        // honour ?company_id= — switch via POST /api/active-company/{id} first.
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $query = DocumentTemplate::query();

        // Hide templates whose post-create / post-update dry-run failed (mirrors
        // WidgetController::index). The flag lives in jsonb metadata under
        // `dry_run_failed`; the three "keep visible" cases (metadata null / key
        // absent / value != true) are covered portably across PG + SQLite.
        $query->where(function ($q) {
            $q->whereNull('metadata')
                ->orWhereNull('metadata->dry_run_failed')
                ->orWhere('metadata->dry_run_failed', '!=', true);
        });

        if ($user->role === 'superadmin' || $user->role === 'admin') {
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere('company_id', $companyId);
            });
        } elseif ($user->role === 'analyst') {
            $query->where(function ($q) use ($user, $companyId) {
                $q->where('is_system', true)
                    ->orWhere(function ($q2) use ($user, $companyId) {
                        $q2->where('company_id', $companyId)
                            ->where(function ($q3) use ($user) {
                                $q3->where('user_id', $user->id)
                                    ->orWhere('is_published', true);
                            });
                    });
            });
        } else {
            // viewer — system + published(company); read-only.
            $query->where(function ($q) use ($companyId) {
                $q->where('is_system', true)
                    ->orWhere(function ($q2) use ($companyId) {
                        $q2->where('company_id', $companyId)
                            ->where('is_published', true);
                    });
            });
        }

        $query->with(['author' => fn ($q) => $q->select('id', 'name', 'email')])
            ->orderByRaw('sort_order is null, sort_order asc')
            ->orderBy('id');

        return response()->json(
            $query->get()->map(fn (DocumentTemplate $t) => $this->buildDocumentPayload($t, withConfig: false))
        );
    }

    /**
     * GET /api/documents/field-catalog
     *
     * Returns the catalogue of substitutable fields the system can inject into a
     * document, grouped by object / deal / buyer / finances / common / discount /
     * branding. Backs the "available fields" reference modal and the placeholder-
     * mapping UI. The source of truth is config('documents.field_catalog'); the
     * object/deal/buyer/finances groups are kept in lock-step with
     * DocumentObjectDataService (canonical ${group.field} keys).
     *
     * Each entry carries the canonical `key`, localized `label`, `group`, the
     * allowed `filters` (words / rouble / format / date / date_words) and an
     * `example` value so the UI can show "estate.price | words → три миллиона …".
     * `pii: true` is surfaced where present (buyer.*) for the UI to flag.
     *
     * Static reference (not company data) — the only gate is auth:sanctum +
     * company.access on the route; any authenticated user may read it.
     *
     * Response: { groups: { object: [{key, label:{ru,en}, group, filters, example, pii?}], ... } }
     */
    public function fieldCatalog(): JsonResponse
    {
        /** @var array<string, array<int, array<string, mixed>>> $catalog */
        $catalog = (array) config('documents.field_catalog', []);

        $groups = [];
        foreach ($catalog as $group => $fields) {
            $groups[$group] = array_map(
                static function (array $field) use ($group): array {
                    $entry = [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'group' => $group,
                        'filters' => array_values((array) ($field['filters'] ?? [])),
                        'example' => $field['example'] ?? null,
                    ];

                    // Surface the PII flag only where the catalogue sets it.
                    if (($field['pii'] ?? false) === true) {
                        $entry['pii'] = true;
                    }

                    return $entry;
                },
                $fields,
            );
        }

        return response()->json(['groups' => $groups]);
    }

    public function show(Request $request, DocumentTemplate $document): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Throws 403 directly when access is denied.
        $this->guardReadable($document, $user, $activeCompanyId);

        return response()->json($this->buildDocumentPayload($document, withConfig: true));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['superadmin', 'admin', 'analyst'], true)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Belt-and-braces guard against a stale active company id whose access
        // was revoked between switch and create.
        if (! $user->canAccessCompany($activeCompanyId)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $request->validate([
            'name' => 'required|array',
            'description' => 'nullable|array',
            'type' => 'required|in:html,docx',
            // `present|array` (not `required`): a brand-new template is created
            // with an empty config ({} / []), and Laravel's `required` treats an
            // empty array as absent → 422. The key must be present but may be
            // empty; the full AI/manual config arrives via update() in M7/M8.
            'config' => 'present|array',
            'source_path' => 'nullable|string',
            'chat_message_id' => 'nullable|exists:chat_messages,id',
        ]);

        $document = DocumentTemplate::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'type' => $request->input('type'),
            'config' => (array) $request->input('config'),
            'source_path' => $request->input('source_path'),
            'is_system' => false,
            'is_published' => false,
            'user_id' => $user->id,
            'company_id' => $activeCompanyId,
            'chat_message_id' => $request->input('chat_message_id'),
        ]);

        return response()->json($this->buildDocumentPayload($document, withConfig: true), 201);
    }

    public function update(Request $request, DocumentTemplate $document): JsonResponse
    {
        $user = $request->user();

        if ($document->is_system) {
            return response()->json(['message' => __('documents.cannot_edit_system')], 403);
        }

        if (! $this->canWrite($request, $document)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $request->validate([
            'name' => 'sometimes|array',
            'description' => 'sometimes|nullable|array',
            'config' => 'sometimes|array',
            'source_path' => 'sometimes|nullable|string',
            'is_published' => ($user->role === 'superadmin' || $user->role === 'admin')
                ? 'sometimes|boolean'
                : 'prohibited',
        ]);

        // Read raw input rather than the validate() return — validate() only
        // echoes back explicitly-listed keys, which would strip nested config
        // entries from a jsonb blob (cf. validate_strips_nested_keys memory).
        $update = [];
        foreach (['name', 'description', 'source_path'] as $key) {
            if ($request->has($key)) {
                $update[$key] = $request->input($key);
            }
        }
        if ($request->has('config')) {
            $update['config'] = (array) $request->input('config');
        }
        if (($user->role === 'superadmin' || $user->role === 'admin') && $request->has('is_published')) {
            $update['is_published'] = $request->boolean('is_published');
        }

        $document->update($update);

        return response()->json($this->buildDocumentPayload($document, withConfig: true));
    }

    public function destroy(Request $request, DocumentTemplate $document): JsonResponse
    {
        if ($document->is_system) {
            return response()->json(['message' => __('documents.cannot_delete_system')], 403);
        }

        if (! $this->canWrite($request, $document)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $document->delete();

        return response()->json(['message' => __('documents.deleted')]);
    }

    public function publish(Request $request, DocumentTemplate $document): JsonResponse
    {
        return $this->setPublished($request, $document, true);
    }

    public function unpublish(Request $request, DocumentTemplate $document): JsonResponse
    {
        return $this->setPublished($request, $document, false);
    }

    /**
     * POST /api/documents/{id}/generate
     *
     * Creates a GeneratedDocument(pending) and dispatches GenerateDocumentJob.
     * Any role that can read the template may generate from it (download-only
     * matrix for viewer). Returns 202 + the generated document id for polling.
     */
    public function generate(Request $request, DocumentTemplate $document): JsonResponse
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Read-ACL: same visibility rules as show().
        $this->guardReadable($document, $user, $activeCompanyId);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'estate_sell_id' => 'nullable|integer',
            'promotion_id' => 'nullable|integer',
            'discount' => 'nullable|numeric',
            'params' => 'nullable|array',
        ]);

        // Promotion gate (M3): when a promotion is selected, it must belong to
        // the active company, be active, and the requested discount must fall in
        // its [discount_min, discount_max] range. This is the access control
        // behind "analyst/viewer set a discount within the promotion range".
        // Without a promotion_id, discount is ungated (free generation, no
        // discount block) — see HtmlDocumentService.
        $promotionId = $request->input('promotion_id');
        if ($promotionId !== null && $promotionId !== '') {
            $this->assertDiscountWithinPromotion(
                (int) $promotionId,
                $activeCompanyId,
                $request->input('discount'),
            );
        }

        // Build the params snapshot: caller params blob plus the well-known keys.
        $params = (array) $request->input('params', []);
        foreach (['estate_sell_id', 'promotion_id', 'discount'] as $key) {
            if ($request->has($key) && $request->input($key) !== null) {
                $params[$key] = $request->input($key);
            }
        }

        $generated = GeneratedDocument::create([
            'document_template_id' => $document->id,
            'company_id' => $activeCompanyId,
            'user_id' => $user->id,
            'title' => $request->input('title')
                ?? ($document->getTranslation('name', app()->getLocale(), false) ?: 'Document'),
            'params' => $params,
            'status' => GeneratedDocument::STATUS_PENDING,
        ]);

        GenerateDocumentJob::dispatch($generated->id);

        return response()->json([
            'message' => __('documents.generation_queued'),
            'generated_document_id' => $generated->id,
        ], 202);
    }

    /**
     * POST /api/documents/{id}/preview-html
     *
     * Synchronous HTML preview of a commercial proposal — the fast path the M4
     * frontend renders into an `<iframe srcdoc>` while the user tweaks the
     * object / discount. Unlike generate() it does NOT touch Gotenberg, does NOT
     * create a GeneratedDocument and does NOT enqueue anything: it resolves the
     * object data, loads branding + the validated promotion and runs
     * HtmlDocumentService::buildHtml() inline.
     *
     * Read-ACL is identical to show()/generate() — any role that can read the
     * template (viewer included) may preview it. The promotion / discount gate is
     * exactly as strict as generate(): same assertDiscountWithinPromotion() →
     * same 422 on a foreign / inactive promotion or an out-of-range discount.
     * This keeps the preview honest: what you see is what you would generate.
     *
     * estate_sell_id is optional — an empty / object-less preview of the bare
     * template is valid (placeholders collapse to empty, never leak raw {{...}}).
     *
     * Response: { html: "<...>" } as application/json (the HTML rides inside a
     * JSON field so the frontend drops it straight into iframe srcdoc).
     */
    public function previewHtml(
        Request $request,
        DocumentTemplate $document,
        DocumentObjectDataResolver $resolver,
        HtmlDocumentService $htmlService,
    ): JsonResponse {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Read-ACL: same visibility rules as show() / generate().
        $this->guardReadable($document, $user, $activeCompanyId);

        $request->validate([
            'estate_sell_id' => 'nullable|integer',
            'promotion_id' => 'nullable|integer',
            'discount' => 'nullable|numeric',
            'locale' => 'nullable|in:ru,en',
        ]);

        // Promotion gate — identical strictness to generate(): a selected
        // promotion must belong to the active company, be active, and the
        // discount must sit inside its range. 422 otherwise.
        $promotionId = $request->input('promotion_id');
        if ($promotionId !== null && $promotionId !== '') {
            $this->assertDiscountWithinPromotion(
                (int) $promotionId,
                $activeCompanyId,
                $request->input('discount'),
            );
        }

        $company = Company::findOrFail($activeCompanyId);

        // Resolve the object fields when an estate_sell_id is supplied; otherwise
        // render the bare template (empty object data). The resolver lives in
        // macrodata-engineer's zone behind the contract; tests mock it.
        $estateSellId = (int) ($request->input('estate_sell_id') ?? 0);
        $objectData = $estateSellId > 0
            ? $resolver->resolve($company, $estateSellId)
            : [];

        $branding = $company->branding;
        $locale = $request->input('locale') ?? app()->getLocale();
        $locale = in_array($locale, ['ru', 'en'], true) ? $locale : 'ru';

        // Re-load the promotion for the discount block (the gate above already
        // proved it belongs to the company / is active when present).
        $promotion = null;
        $discount = 0.0;
        if ($promotionId !== null && $promotionId !== '') {
            $promotion = Promotion::find((int) $promotionId);
            $discount = (float) ($request->input('discount') ?? 0);
        }

        $html = $htmlService->buildHtml($document, $objectData, $branding, $locale, $promotion, $discount);

        return response()->json(['html' => $html]);
    }

    /**
     * POST /api/documents/{id}/source-file
     *
     * Upload the source template file for a template (multipart, field `file`):
     *   - docx templates accept a `.docx` (stored as template.docx).
     *   - html templates accept a `.html` / `.htm` (stored as template.html) — the
     *     mirror of the docx flow, so an author can upload a hand-written branded
     *     КП instead of authoring config.html.
     * The uploaded file is stored on the documents disk under
     * document-templates/{id}/ and its path written to source_path. A subsequent
     * generation / preview substitutes its ${...} / {{...}} placeholders.
     *
     * Write-ACL: identical to update() — owner analyst / admin of the company /
     * superadmin. viewer → 403. System templates are read-only.
     */
    public function uploadSourceFile(Request $request, DocumentTemplate $document): JsonResponse
    {
        if ($document->is_system) {
            return response()->json(['message' => __('documents.cannot_edit_system')], 403);
        }

        if (! $this->canWrite($request, $document)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        if (! in_array($document->type, ['docx', 'html'], true)) {
            return response()->json(['message' => __('documents.not_a_docx_template')], 422);
        }

        // Per-type mime whitelist. docx is a zip container (so OOXML / zip /
        // octet-stream — libmagic may not know docx); html is text/html but some
        // clients send text/plain / octet-stream for a .html, so we accept those
        // and gate on the extension below (the same finfo-trap reasoning as docx).
        $mimeRule = $document->type === 'docx'
            ? 'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream'
            : 'mimetypes:text/html,text/plain,application/octet-stream';

        $request->validate([
            'file' => [
                'required',
                'file',
                $mimeRule,
                'max:10240', // KB → 10 MB
            ],
        ]);

        $file = $request->file('file');

        // Explicit extension guard (case-insensitive) — asserts the expected
        // extension without going through finfo, so it works regardless of the
        // container's libmagic mime database.
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if ($document->type === 'docx') {
            if ($ext !== 'docx') {
                return response()->json(['message' => __('documents.must_be_docx')], 422);
            }
            $filename = 'template.docx';
        } else {
            if (! in_array($ext, ['html', 'htm'], true)) {
                return response()->json(['message' => __('documents.must_be_html')], 422);
            }
            $filename = 'template.html';
        }

        $disk = Storage::disk(config('documents.disk'));

        $oldPath = $document->source_path;

        // Deterministic per-template path so re-uploads overwrite cleanly;
        // storeAs preserves the disk's public visibility.
        $path = $file->storeAs("document-templates/{$document->id}", $filename, config('documents.disk'));

        $document->update(['source_path' => $path]);

        // Best-effort cleanup of a previous source at a different path.
        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $path && $disk->exists($oldPath)) {
            $disk->delete($oldPath);
        }

        return response()->json([
            'message' => __('documents.source_uploaded'),
            'source_path' => $path,
        ]);
    }

    /**
     * GET /api/documents/{id}/placeholders
     *
     * Returns the placeholder tokens declared in the uploaded source (for the
     * mapping UI + the AI field-proposal flow). For docx: PHPWord getVariables().
     * For html: ${...} / {{...}} tokens scanned out of the HTML source (or, when
     * no file has been uploaded, the config.html body). Read-ACL is the template's
     * read-ACL. 422 when an html template has neither source nor config.html, and
     * when a docx template has no source.
     *
     * Response: { placeholders: ["estate.price", "deal.date", ...] }
     */
    public function placeholders(
        Request $request,
        DocumentTemplate $document,
        DocxTemplateService $docxService,
    ): JsonResponse {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->guardReadable($document, $user, $activeCompanyId);

        if ($document->type === 'html') {
            return $this->htmlPlaceholders($document);
        }

        $sourcePath = $document->source_path;

        if (! is_string($sourcePath) || $sourcePath === '') {
            return response()->json(['message' => __('documents.no_source_file')], 422);
        }

        $disk = Storage::disk(config('documents.disk'));

        if (! $disk->exists($sourcePath)) {
            return response()->json(['message' => __('documents.no_source_file')], 422);
        }

        // PHPWord reads from the filesystem; pull the disk-stored .docx to a temp
        // file, extract its variables, then drop the temp.
        $tmp = tempnam(sys_get_temp_dir(), 'vizion_docx_ph_').'.docx';
        file_put_contents($tmp, $disk->get($sourcePath));

        try {
            $placeholders = $docxService->extractPlaceholders($tmp);
        } catch (Throwable $e) {
            return response()->json(['message' => __('documents.invalid_docx')], 422);
        } finally {
            @unlink($tmp);
        }

        return response()->json(['placeholders' => array_values($placeholders)]);
    }

    /**
     * Extract ${...} / {{...}} placeholder NAMES (filter chain stripped) from an
     * html template's uploaded source — or, when none is uploaded, its
     * config.html body. 422 when neither carries HTML.
     */
    private function htmlPlaceholders(DocumentTemplate $document): JsonResponse
    {
        $html = null;

        $sourcePath = $document->source_path;
        if (is_string($sourcePath) && $sourcePath !== '') {
            $disk = Storage::disk(config('documents.disk'));
            if ($disk->exists($sourcePath)) {
                $loaded = $disk->get($sourcePath);
                $html = is_string($loaded) ? $loaded : null;
            }
        }

        if ($html === null) {
            $config = $document->config ?? [];
            $html = is_string($config['html'] ?? null) ? $config['html'] : null;
        }

        if ($html === null || $html === '') {
            return response()->json(['message' => __('documents.no_source_file')], 422);
        }

        return response()->json(['placeholders' => $this->scanHtmlPlaceholders($html)]);
    }

    /**
     * Scan an HTML string for ${name|...} and {{name|...}} placeholders, returning
     * the unique NAME parts (before the first `|`), filter chain stripped.
     *
     * @return array<int, string>
     */
    private function scanHtmlPlaceholders(string $html): array
    {
        $names = [];

        foreach (['/\$\{\s*([^}|]+(?:\|[^}]*)?)\s*\}/u', '/\{\{\s*([^}|]+(?:\|[^}]*)?)\s*\}\}/u'] as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $body) {
                    $name = trim(explode('|', $body)[0]);
                    if ($name !== '') {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * GET /api/documents/generated/{id}
     *
     * Returns the status of a generation. Read-ACL is the template's read-ACL.
     */
    public function generatedStatus(Request $request, GeneratedDocument $generated): JsonResponse
    {
        $this->assertGeneratedReadable($request, $generated);

        return response()->json([
            'id' => $generated->id,
            'document_template_id' => $generated->document_template_id,
            'title' => $generated->title,
            'status' => $generated->status,
            'pdf_path' => $generated->pdf_path,
            'docx_path' => $generated->docx_path,
            'error' => $generated->error,
            'created_at' => optional($generated->created_at)->toIso8601String(),
            'updated_at' => optional($generated->updated_at)->toIso8601String(),
        ]);
    }

    /**
     * GET /api/documents/generated/{id}/download?format=pdf|docx
     *
     * Streams the generated file. Read-ACL is the template's read-ACL.
     * format=pdf is served for both html and docx templates; format=docx is
     * served only when a filled .docx exists (docx-type generation, M5).
     */
    public function download(Request $request, GeneratedDocument $generated): StreamedResponse|JsonResponse
    {
        $this->assertGeneratedReadable($request, $generated);

        $format = $request->query('format', 'pdf');

        if (! in_array($format, ['pdf', 'docx'], true)) {
            return response()->json(['message' => __('documents.unsupported_format')], 422);
        }

        $path = $format === 'docx' ? $generated->docx_path : $generated->pdf_path;

        if ($generated->status !== GeneratedDocument::STATUS_DONE || $path === null) {
            return response()->json(['message' => __('documents.file_not_ready')], 409);
        }

        $disk = Storage::disk(config('documents.disk'));

        if (! $disk->exists($path)) {
            return response()->json(['message' => __('documents.file_not_ready')], 409);
        }

        return $disk->download($path, "document-{$generated->id}.{$format}");
    }

    /**
     * Validate that the selected promotion is usable for the active company and
     * that the requested discount sits inside its allowed range.
     *
     * Throws a 422 ValidationException (keyed under `promotion_id` / `discount`)
     * when: the promotion does not exist, belongs to another company, is
     * inactive, or the discount is outside [discount_min, discount_max]. A null
     * / omitted discount is treated as 0 (no discount) and validated against the
     * range like any other value, so a promotion whose min is > 0 still requires
     * an explicit in-range discount.
     *
     * @throws ValidationException
     */
    private function assertDiscountWithinPromotion(int $promotionId, int $activeCompanyId, mixed $discount): void
    {
        $promotion = Promotion::find($promotionId);

        if ($promotion === null || (int) $promotion->company_id !== $activeCompanyId) {
            throw ValidationException::withMessages([
                'promotion_id' => [__('promotions.wrong_company')],
            ]);
        }

        if (! $promotion->is_active) {
            throw ValidationException::withMessages([
                'promotion_id' => [__('promotions.inactive')],
            ]);
        }

        $value = $discount === null || $discount === '' ? 0.0 : (float) $discount;
        $min = (float) $promotion->discount_min;
        $max = (float) $promotion->discount_max;

        if ($value < $min || $value > $max) {
            throw ValidationException::withMessages([
                'discount' => [__('promotions.discount_out_of_range')],
            ]);
        }
    }

    /**
     * Read-ACL for a GeneratedDocument: delegate to its template's visibility.
     * A missing template (cascade should prevent this) is treated as forbidden.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException 403
     */
    private function assertGeneratedReadable(Request $request, GeneratedDocument $generated): void
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->assertEntityIdReadable(
            DocumentTemplate::class,
            (int) $generated->document_template_id,
            $user,
            $activeCompanyId,
        );
    }

    /**
     * Shared body of publish/unpublish: ACL + state mutation + response.
     * Only admin/superadmin (of the template's company; superadmin cross-company)
     * may publish; system templates are rejected.
     */
    private function setPublished(Request $request, DocumentTemplate $document, bool $value): JsonResponse
    {
        $user = $request->user();

        if ($document->is_system) {
            return response()->json(['message' => __('documents.cannot_publish_system')], 403);
        }

        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && (int) $document->company_id === $activeCompanyId);

        if (! $allowed) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $document->update(['is_published' => $value]);

        return response()->json($this->buildDocumentPayload($document, withConfig: false));
    }

    /**
     * Write-ACL (update / delete): owner OR admin/superadmin of the template's
     * owning company. Superadmin is cross-company. System templates are filtered
     * out by the callers before this runs.
     */
    private function canWrite(Request $request, DocumentTemplate $document): bool
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if ($user->role === 'superadmin') {
            return true;
        }

        if ($user->role === 'admin' && (int) $document->company_id === $activeCompanyId) {
            return true;
        }

        // analyst may write their own template (within the active company);
        // viewer never writes.
        if ($user->role === 'analyst'
            && $document->user_id === $user->id
            && (int) $document->company_id === $activeCompanyId
        ) {
            return true;
        }

        return false;
    }

    /**
     * Tight projection of a DocumentTemplate for API responses. config is
     * included only where the frontend needs the full blob (show / store /
     * update), not on index / publish / unpublish responses.
     */
    private function buildDocumentPayload(DocumentTemplate $document, bool $withConfig): array
    {
        $document->loadMissing(['author' => fn ($q) => $q->select('id', 'name', 'email')]);

        $payload = [
            'id' => $document->id,
            'name' => json_decode($document->getRawOriginal('name'), true),
            'description' => $document->getRawOriginal('description') !== null
                ? json_decode($document->getRawOriginal('description'), true)
                : null,
            'type' => $document->type,
            'source_path' => $document->source_path,
            'is_system' => (bool) $document->is_system,
            'is_published' => (bool) $document->is_published,
            'sort_order' => $document->sort_order,
            'user_id' => $document->user_id,
            'chat_message_id' => $document->chat_message_id,
            'created_at' => optional($document->created_at)->toIso8601String(),
            'updated_at' => optional($document->updated_at)->toIso8601String(),
            'author' => $document->author
                ? [
                    'id' => $document->author->id,
                    'name' => $document->author->name,
                    'email' => $document->author->email,
                ]
                : null,
        ];

        if ($withConfig) {
            $payload['config'] = $document->config;
        }

        return $payload;
    }
}
