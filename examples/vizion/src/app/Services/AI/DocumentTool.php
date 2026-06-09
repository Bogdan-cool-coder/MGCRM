<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ChatMessageEvent;
use App\Models\DocumentTemplate;
use App\Services\Documents\HtmlDocumentService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

/**
 * DocumentTool — Prism toolset for the document_template chat type. The document
 * mirror of WidgetTool / ReportTool: a probe_data tool plus two document tools.
 *
 * Deliberate reuse (no copy-paste of machinery):
 *   - probe_data: the *exact same* tool definition ReportTool exposes
 *     (ReportTool::probeDataTool). Read-only + entity-agnostic — it inspects
 *     MacroData, not the document being built — so there is one probe contract
 *     shared across report / widget / document generation. AI uses it to see
 *     the real MacroData fields it can map placeholders onto.
 *
 * Two document tools:
 *   - propose_document_fields (FIRST step of the docx mapping flow): for an
 *     uploaded Word template the AI reads the document text + its ${tokens} and
 *     proposes a placeholder→field mapping. Validated, NOT saved — the user
 *     confirms it on the frontend (M8) and a later turn persists it. Mirror of
 *     WidgetTool::propose_widget_variants.
 *   - generate_document_template: creates a custom HTML commercial-proposal
 *     template (type='html') from a description, with a post-save dry-run via
 *     HtmlDocumentService + semantic-retry escalation. Mirror of create_report.
 *
 * Semantic-retry counter ($dryRunState) and event emitting (ChatEventEmitter)
 * follow the same per-turn contract as ReportTool / WidgetTool — see getTools()
 * and ChatService::runForJob() for the "fresh state per turn" rationale.
 */
class DocumentTool
{
    public function __construct(
        protected ReportTool $reportTool,
        protected HtmlDocumentService $htmlDocumentService,
    ) {}

    /**
     * Build the document_template toolset for a chat.
     *
     * @param  Chat  $chat
     * @param  object|null  $dryRunState  Per-turn mutable container with a public
     *                                    int `failures` property, shared with the
     *                                    generate_document_template closure so the
     *                                    semantic-retry counter is per-turn (not
     *                                    per-process). Mirrors WidgetTool::getTools().
     * @param  ChatEventEmitter|null  $emitter  Optional event emitter for the
     *                                    async streaming pipeline.
     * @return Tool[]
     */
    public function getTools(Chat $chat, ?object $dryRunState = null, ?ChatEventEmitter $emitter = null): array
    {
        $dryRunState ??= (object) ['failures' => 0];

        return [
            // Reuse the report toolset's probe_data verbatim — single source of
            // truth for the probe contract.
            $this->reportTool->probeDataTool($chat, $emitter),
            $this->proposeDocumentFieldsTool($chat, $emitter),
            $this->generateDocumentTemplateTool($chat, $dryRunState, $emitter),
        ];
    }

    // -------------------------------------------------------------------------
    // propose_document_fields
    // -------------------------------------------------------------------------

    /**
     * Tool: propose_document_fields — the FIRST step of the docx field-mapping
     * flow. For an uploaded .docx the AI reads the document text + its ${tokens}
     * (injected into the system prompt by ChatService) and proposes a mapping
     * from each placeholder to a substitutable field (a field-catalog key OR a
     * real MacroData model field). This tool:
     *
     *   1. Decodes a JSON array of mapping objects
     *      {token, suggested_field, model?, confidence?}.
     *   2. Validates that each suggested_field exists — either as a
     *      config/documents.php field-catalog key, or as a real attribute of the
     *      named MacroData model (reflection / column probe). Invalid entries are
     *      NOT fatal: they are reported in `rejected`, the valid ones still come
     *      back so the user gets a usable draft.
     *   3. Does NOT write field_mapping anywhere and does NOT touch the template
     *      — the proposal is ephemeral; the user confirms it on the frontend
     *      (M8) and a later turn / PUT persists config.field_mapping.
     *   4. Emits a `document_fields_proposed` event carrying the validated
     *      placeholders so the frontend can render confirmable mapping cards.
     */
    protected function proposeDocumentFieldsTool(Chat $chat, ?ChatEventEmitter $emitter = null): Tool
    {
        $self = $this;

        return (new Tool)
            ->as('propose_document_fields')
            ->for('Предложить маппинг плейсхолдеров загруженного Word-шаблона (.docx) на подставляемые поля. Параметр placeholders — JSON-массив объектов {token, suggested_field, model?, confidence?}. token — имя плейсхолдера БЕЗ ${} (например "agreement_number"). suggested_field — КАНОНИЧЕСКИЙ ключ из справочника полей (field-catalog), вида group.field (например estate.price, deal.number, buyer.full_name), ИЛИ реальное поле модели MacroData. model — короткое имя модели MacroData, если suggested_field — поле модели (например EstateSells). confidence — 0..1, насколько уверен. Маппинг НЕ сохраняется — пользователь подтвердит. Сначала изучи текст документа (он в системном промпте) и реальные поля через probe_data.')
            ->withStringParameter('placeholders', 'JSON-массив из объектов: [{"token":"agreement_number","suggested_field":"deal.number","model":null,"confidence":0.95},{"token":"client_name","suggested_field":"buyer.full_name","confidence":0.7}]. token — без ${}. suggested_field — КАНОНИЧЕСКИЙ ключ field-catalog (estate.price, estate.complex_name, deal.number, buyer.full_name, discount.percent, brand_header, req_<ключ>, ...) или реальное поле MacroData-модели (тогда укажи model).')
            ->using(function (string $placeholders) use ($chat, $emitter, $self): string {
                return $self->runProposeDocumentFields($placeholders, $chat, $emitter);
            });
    }

    /**
     * @internal Public for the tool-closure $self access only. Treat as protected.
     */
    public function runProposeDocumentFields(
        string $placeholders,
        Chat $chat,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $decoded = json_decode($placeholders, true);

            if (!is_array($decoded) || $decoded === [] || array_keys($decoded) !== range(0, count($decoded) - 1)) {
                return json_encode([
                    'success' => false,
                    'error'   => 'placeholders must be a non-empty JSON array of {token, suggested_field} objects.',
                    'hint'    => 'Передай маппинг: [{"token":"...","suggested_field":"...","model":"...","confidence":0.9}, ...].',
                ], JSON_UNESCAPED_UNICODE);
            }

            $catalogKeys = $this->fieldCatalogKeys();

            $valid    = [];
            $rejected = [];

            foreach ($decoded as $i => $entry) {
                if (!is_array($entry)) {
                    $rejected[] = ['position' => $i, 'reason' => 'entry must be an object'];
                    continue;
                }

                $token = $entry['token'] ?? null;
                if (!is_string($token) || $token === '') {
                    $rejected[] = ['position' => $i, 'reason' => 'missing token'];
                    continue;
                }
                // Tokens are placeholder identifiers, never decorated with ${}.
                $token = ltrim(rtrim($token, '}'), '${');

                $suggested = $entry['suggested_field'] ?? null;
                if (!is_string($suggested) || $suggested === '') {
                    $rejected[] = ['token' => $token, 'reason' => 'missing suggested_field'];
                    continue;
                }

                $model = isset($entry['model']) && is_string($entry['model']) && $entry['model'] !== ''
                    ? $entry['model']
                    : null;

                $resolution = $this->resolveSuggestedField($suggested, $model, $catalogKeys);
                if (!$resolution['ok']) {
                    $rejected[] = [
                        'token'           => $token,
                        'suggested_field' => $suggested,
                        'model'           => $model,
                        'reason'          => $resolution['reason'],
                    ];
                    continue;
                }

                $confidence = null;
                if (isset($entry['confidence']) && is_numeric($entry['confidence'])) {
                    $confidence = max(0.0, min(1.0, (float) $entry['confidence']));
                }

                $valid[] = [
                    'token'           => $token,
                    'suggested_field' => $suggested,
                    'model'           => $model,
                    'source'          => $resolution['source'], // 'catalog' | 'macrodata'
                    'confidence'      => $confidence,
                ];
            }

            if ($valid === []) {
                return json_encode([
                    'success'  => false,
                    'error'    => 'No valid mappings — every suggested_field failed validation.',
                    'rejected' => $rejected,
                    'hint'     => 'suggested_field должен быть КАНОНИЧЕСКИМ ключом field-catalog вида group.field (estate.price, estate.complex_name, deal.number, buyer.full_name, discount.percent, brand_header, req_<ключ>, ...) ИЛИ реальным полем MacroData-модели (укажи model и проверь поле через probe_data).',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Emit the proposal event so the frontend can render mapping cards.
            // Payload mirrors the tool result so SSE-stream and reload-replay
            // see the same structured shape.
            if ($emitter !== null) {
                try {
                    $emitter->emit(ChatMessageEvent::TYPE_DOCUMENT_FIELDS_PROPOSED, [
                        'placeholders' => $valid,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('DocumentTool: failed to emit document_fields_proposed event', [
                        'chat_id' => $chat->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $response = [
                'success'            => true,
                'proposed'           => true,
                'placeholders_count' => count($valid),
                'placeholders'       => $valid,
                'hint'               => 'Маппинг предложен пользователю. НЕ сохраняй его сейчас — пользователь подтвердит на интерфейсе. Объясни кратко, что ты предложил, и какие токены остались без уверенного маппинга.',
            ];

            if ($rejected !== []) {
                $response['rejected'] = $rejected;
            }

            return json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Resolve a suggested_field to its source. A field is acceptable when it is:
     *   - a field-catalog key (config/documents.php), OR a req_<key> requisite
     *     (the catalog declares the wildcard `req_*`), OR
     *   - a real attribute of the named MacroData model (column listing via a
     *     reflection-safe probe — model class must exist under
     *     App\Models\MacroData and declare the column).
     *
     * @param  array<int, string>  $catalogKeys
     * @return array{ok: bool, source?: string, reason?: string}
     */
    private function resolveSuggestedField(string $suggested, ?string $model, array $catalogKeys): array
    {
        // 1) field-catalog exact key.
        if (in_array($suggested, $catalogKeys, true)) {
            return ['ok' => true, 'source' => 'catalog'];
        }

        // 2) requisite wildcard: req_<key> matches the catalog's `req_*` entry.
        if (in_array('req_*', $catalogKeys, true) && str_starts_with($suggested, 'req_') && strlen($suggested) > 4) {
            return ['ok' => true, 'source' => 'catalog'];
        }

        // 3) real MacroData model field. Requires a model so we know where to look.
        if ($model !== null) {
            // Reject obviously-malformed identifiers before reflecting.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $model)) {
                return ['ok' => false, 'reason' => "model '{$model}' is not a valid identifier"];
            }

            $modelClass = "App\\Models\\MacroData\\{$model}";
            if (!class_exists($modelClass)) {
                return ['ok' => false, 'reason' => "model '{$model}' not found in App\\Models\\MacroData"];
            }

            // The field must be a bare column identifier (no dot-paths in docx
            // mapping — substitution is over flat resolved values).
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $suggested)) {
                return ['ok' => false, 'reason' => "suggested_field '{$suggested}' must be a bare column identifier"];
            }

            if ($this->macrodataModelHasField($modelClass, $suggested)) {
                return ['ok' => true, 'source' => 'macrodata'];
            }

            return ['ok' => false, 'reason' => "field '{$suggested}' not found on {$model} (probe_data to confirm the real column name)"];
        }

        return [
            'ok'     => false,
            'reason' => "'{$suggested}' is neither a field-catalog key nor a MacroData field (pass `model` to map onto a model field)",
        ];
    }

    /**
     * Check that a MacroData model declares a given column. Read-only and
     * connection-free: inspects the model's `getKeyName()`, `$fillable`, and the
     * declared `casts()` map (which is where the read-only MacroData models
     * actually enumerate their columns — `$fillable` is empty on them). We use a
     * normal constructor (Eloquent's constructor only sets attributes — it does
     * NOT hit the DB) so the `casts()` method is merged; `newInstanceWithout
     * Constructor()` would skip it and leave casts empty. We deliberately do NOT
     * open a MacroData connection here (no company creds in a tool closure) — so
     * a real-but-unenumerated column is reported as not-found, the safe direction
     * (the AI is nudged to probe_data first).
     */
    private function macrodataModelHasField(string $modelClass, string $field): bool
    {
        try {
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $modelClass();
        } catch (\Throwable $e) {
            return false;
        }

        if (!$instance instanceof \Illuminate\Database\Eloquent\Model) {
            return false;
        }

        if ($instance->getKeyName() === $field) {
            return true;
        }

        if (in_array($field, $instance->getFillable(), true)) {
            return true;
        }

        try {
            // getCasts() merges the casts() method map — the canonical column
            // enumeration on the read-only MacroData models.
            if (array_key_exists($field, $instance->getCasts())) {
                return true;
            }
        } catch (\Throwable $e) {
            // ignore — fall through to false.
        }

        return false;
    }

    /**
     * Flatten config/documents.php field_catalog into a list of valid keys
     * (across all groups). The wildcard `req_*` is preserved as-is so
     * resolveSuggestedField() can recognise dynamic requisite tokens.
     *
     * @return array<int, string>
     */
    private function fieldCatalogKeys(): array
    {
        $catalog = config('documents.field_catalog', []);
        $keys = [];

        foreach ($catalog as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $entry) {
                if (is_array($entry) && isset($entry['key']) && is_string($entry['key'])) {
                    $keys[] = $entry['key'];
                }
            }
        }

        return $keys;
    }

    // -------------------------------------------------------------------------
    // generate_document_template
    // -------------------------------------------------------------------------

    /**
     * Tool: generate_document_template — AI creates (or updates the chat's
     * pinned) HTML commercial-proposal template.
     *
     * Flow mirrors create_report:
     *   1. Decode name + config JSON. config carries the HTML body (config.html)
     *      with {{placeholder}} tokens drawn from the field-catalog.
     *   2. Create the DocumentTemplate (type='html', company_id/user_id from the
     *      chat) — or, when the chat already pins a document_template, update it.
     *   3. Pin chat.document_id (the chat_message_id back-link is stamped in
     *      ChatService::runForJob(), mirroring report/widget).
     *   4. Dry-run via HtmlDocumentService::buildHtml() with empty data; on a
     *      thrown exception OR an empty render, tag metadata.dry_run_failed=true
     *      and run the semantic-retry escalation.
     */
    protected function generateDocumentTemplateTool(Chat $chat, object $dryRunState, ?ChatEventEmitter $emitter = null): Tool
    {
        $self = $this;

        return (new Tool)
            ->as('generate_document_template')
            ->for('Создать кастомный HTML-шаблон коммерческого предложения (КП) по описанию пользователя. Параметры — JSON строки. name: {"ru":"...","en":"..."}. config: JSON с ключом html (HTML-разметка КП с КАНОНИЧЕСКИМИ плейсхолдерами ${group.field|filter} из field-catalog: ${estate.complex_name}, ${estate.price|format} ₽, ${estate.area|format} м², ${discount.percent|format}%, ${brand_header}, ...) и опционально css. Если у чата уже есть шаблон — он будет обновлён.')
            ->withStringParameter('name', 'JSON строка: {"ru":"Название","en":"Title"}')
            ->withStringParameter('config', 'Полный JSON конфиг шаблона: {"html":"<section>...${estate.complex_name}...${estate.price|format} ₽...</section>","css":"section{padding:24px}"}. html — разметка КП с КАНОНИЧЕСКИМИ плейсхолдерами ${group.field|filter} из справочника подставляемых полей (field-catalog). Бренд (плоские): ${brand_header}/${brand_footer}/${req_<ключ>}. Объект: ${estate.complex_name}/${estate.number}/${estate.address}/${estate.area|format} м²/${estate.price|format} ₽ (${estate.price|words})/${estate.price_m2|format}. Сделка: ${deal.number}/${deal.date|date_words}/${deal.sum|format}. Скидка: ${discount.label}/${discount.percent|format}%/${discount.amount|format}/${discount.price_discounted|format}. Дата: ${common.today|date_words}. Фильтры: format (число с разделителями), words (сумма прописью), rouble, date, date_words.')
            ->using(function (string $name, string $config) use ($chat, $dryRunState, $emitter, $self): string {
                $namePreview = $self->reportTool->summariseTitle($name);
                $configSummary = $self->summariseDocumentConfig($config);
                $self->reportTool->emitToolCall($emitter, 'generate_document_template', array_merge(
                    ['name' => $namePreview],
                    $configSummary,
                ));

                $resultJson = $self->runGenerateDocumentTemplate($name, $config, $chat, $dryRunState, $emitter);
                $self->reportTool->emitToolResultFromJson($emitter, 'generate_document_template', $resultJson);
                return $resultJson;
            });
    }

    /**
     * @internal Public for the tool-closure $self access only. Treat as protected.
     */
    public function runGenerateDocumentTemplate(
        string $name,
        string $config,
        Chat $chat,
        object $dryRunState,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $nameArr   = json_decode($name, true);
            $configArr = json_decode($config, true);

            if (!$nameArr || !$configArr) {
                return json_encode(['error' => 'Invalid JSON in name or config']);
            }

            $shapeErrors = $this->prevalidateHtmlConfig($configArr);
            if (!empty($shapeErrors)) {
                return json_encode([
                    'success' => false,
                    'errors'  => $shapeErrors,
                    'hint'    => 'config должен содержать непустой строковый ключ html — разметку КП с плейсхолдерами {{token}}. css опционален (строка).',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Update the chat's pinned template when present, otherwise create.
            $template = $chat->document;

            if ($template) {
                $template->update([
                    'name'   => $nameArr,
                    'config' => $configArr,
                ]);
                $created = false;
            } else {
                $template = DocumentTemplate::create([
                    'name'         => $nameArr,
                    'type'         => 'html',
                    'config'       => $configArr,
                    'is_system'    => false,
                    'is_published' => false,
                    'user_id'      => $chat->user_id,
                    'company_id'   => $chat->company_id,
                ]);

                // Mirror report/widget pinning: chat.document_id now; the
                // document.chat_message_id back-link is stamped in
                // ChatService::runForJob().
                $chat->update(['document_id' => $template->id]);
                $created = true;
            }

            return $this->runDryRunAndBuildResponse($template, $chat, $dryRunState, $created, $emitter);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Pre-validation
    // -------------------------------------------------------------------------

    /**
     * Validate the html-template config shape before it reaches the dry-run.
     * HtmlDocumentService::buildHtml() reads config.html (string body) and
     * optional config.css; a missing / non-string html body produces a blank
     * document, so we reject it at tool-level for an actionable error.
     *
     * @param  array<string, mixed>  $configArr
     * @return list<array{type: string, message: string}>
     */
    protected function prevalidateHtmlConfig(array $configArr): array
    {
        $errors = [];

        $html = $configArr['html'] ?? null;
        if (!is_string($html) || trim($html) === '') {
            $errors[] = [
                'type'    => 'missing_html',
                'message' => 'config.html is required and must be a non-empty HTML string (the КП body with {{token}} placeholders).',
            ];
        }

        if (array_key_exists('css', $configArr) && $configArr['css'] !== null && !is_string($configArr['css'])) {
            $errors[] = [
                'type'    => 'invalid_css',
                'message' => 'config.css must be a string or omitted.',
            ];
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // Dry-run
    // -------------------------------------------------------------------------

    /**
     * Run the document dry-run via HtmlDocumentService::buildHtml() with empty
     * data + no branding / promotion (buildHtml never fails on those — missing
     * tokens collapse to empty) and build the tool JSON response. Mirrors
     * ReportTool / WidgetTool dry-run.
     *
     * A dry-run "failure" here is either a thrown exception (malformed config)
     * or a structurally-empty render (buildHtml produced no usable body — the
     * config.html slipped past pre-validation but renders to nothing). Either
     * way we tag the template and run the semantic-retry escalation.
     */
    protected function runDryRunAndBuildResponse(
        DocumentTemplate $template,
        Chat $chat,
        object $dryRunState,
        bool $created,
        ?ChatEventEmitter $emitter = null,
    ): string {
        $dryRunEnabled = (bool) config('ai.dry_run.enabled', true);

        if (!$dryRunEnabled) {
            return $this->buildSuccessResponse($template, $created, samplePreview: null);
        }

        $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_START, [
            'document_id' => $template->id,
            'tool'        => 'generate_document_template',
        ]);

        $startedAt = microtime(true);

        try {
            // Empty data + ru locale + no branding / promotion: a structural
            // render check, not a data check. buildHtml tolerates all of these.
            $html = $this->htmlDocumentService->buildHtml(
                $template->fresh(),
                [],
                null,
                $chat->user?->locale ?? 'ru',
            );

            if (!is_string($html) || trim($html) === '') {
                $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                    'document_id' => $template->id,
                    'tool'        => 'generate_document_template',
                    'success'     => false,
                    'ms'          => (int) round((microtime(true) - $startedAt) * 1000),
                    'message'     => 'buildHtml() produced an empty document (unusable config.html)',
                ]);

                return $this->handleDryRunFailure(
                    $template,
                    $chat,
                    $dryRunState,
                    new \RuntimeException('HtmlDocumentService::buildHtml() produced an empty document — config.html rendered to nothing. Check the HTML body and that placeholders are wrapped in {{ }}.'),
                    $emitter,
                );
            }

            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'document_id' => $template->id,
                'tool'        => 'generate_document_template',
                'success'     => true,
                'ms'          => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // A tiny preview: length + the head of the rendered HTML, capped.
            $preview = [
                'html_length' => mb_strlen($html),
                'head'        => mb_substr($html, 0, 240),
            ];

            return $this->buildSuccessResponse($template, $created, samplePreview: $preview);
        } catch (\Throwable $e) {
            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'document_id'     => $template->id,
                'tool'            => 'generate_document_template',
                'success'         => false,
                'ms'              => (int) round((microtime(true) - $startedAt) * 1000),
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            return $this->handleDryRunFailure($template, $chat, $dryRunState, $e, $emitter);
        }
    }

    /**
     * Build the success-shaped JSON the document tool returns when dry-run passes.
     *
     * @param  array<string, mixed>|null  $samplePreview
     */
    protected function buildSuccessResponse(
        DocumentTemplate $template,
        bool $created,
        ?array $samplePreview,
    ): string {
        $response = [
            'success'     => true,
            'document_id' => $template->id,
        ];

        if ($created) {
            $response['url']     = "/api/documents/{$template->id}";
            $response['created'] = true;
        } else {
            $response['updated'] = true;
        }

        if ($samplePreview !== null) {
            $response['preview'] = $samplePreview;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle a document dry-run failure: tag template.metadata.dry_run_failed,
     * bump the per-turn counter, and escalate the hint from "try again" to
     * "stop trying" once max_semantic_retries is exceeded. Mirrors
     * ReportTool::handleDryRunFailure / WidgetTool::handleDryRunFailure.
     */
    protected function handleDryRunFailure(
        DocumentTemplate $template,
        Chat $chat,
        object $dryRunState,
        \Throwable $e,
        ?ChatEventEmitter $emitter = null,
    ): string {
        Log::warning('DocumentTool dry-run failed', [
            'chat_id'         => $chat->id,
            'document_id'     => $template->id,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
        ]);

        try {
            $existing = $template->metadata ?? [];
            $template->update([
                'metadata' => array_merge($existing, [
                    'dry_run_failed' => true,
                    'dry_run_error'  => [
                        'exception_class' => get_class($e),
                        'message'         => $e->getMessage(),
                        'tool'            => 'generate_document_template',
                        'at'              => now()->toIso8601String(),
                    ],
                ]),
            ]);
        } catch (\Throwable $tagErr) {
            Log::warning('DocumentTool: could not tag template.metadata.dry_run_failed', [
                'document_id' => $template->id,
                'error'       => $tagErr->getMessage(),
            ]);
        }

        $dryRunState->failures = ($dryRunState->failures ?? 0) + 1;

        $maxRetries = (int) config('ai.dry_run.max_semantic_retries', 2);
        $exhausted  = $dryRunState->failures >= $maxRetries;

        $emitter?->emit(ChatMessageEvent::TYPE_RETRY, [
            'attempt'   => $dryRunState->failures,
            'limit'     => $maxRetries,
            'exhausted' => $exhausted,
            'reason'    => 'dry_run_failed',
            'tool'      => 'generate_document_template',
        ]);

        $hint = $exhausted
            ? "This is dry-run failure #{$dryRunState->failures} in a row "
                . "(limit: {$maxRetries}). STOP trying to create or update the document template "
                . 'automatically. In your next reply, do NOT call any tool. Explain to '
                . 'the user that you could not build a working КП template and ask them to '
                . 'refine the request (which sections, which fields, which layout).'
            : 'Document template row was saved (kept as debug artefact) but the HTML render '
                . 'via HtmlDocumentService::buildHtml() failed or produced an empty document. Try '
                . 'a simpler config: a single valid HTML body in config.html, {{token}} '
                . 'placeholders from the field-catalog, optional config.css. If you cannot find '
                . 'a working config quickly, stop and ask the user.';

        $payload = [
            'success'     => false,
            'document_id' => $template->id,
            'errors'      => [[
                'type'            => 'dry_run_exception',
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]],
            'dry_run_failure_count'   => $dryRunState->failures,
            'dry_run_failure_limit'   => $maxRetries,
            'dry_run_limit_exhausted' => $exhausted,
            'hint'                    => $hint,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------------------------
    // Summary helpers
    // -------------------------------------------------------------------------

    /**
     * Pluck a short structural summary from the JSON-stringified document config
     * for the tool_call event. Best-effort — never throws.
     *
     * @return array<string, mixed>
     * @internal Public for the tool-closure $self access only.
     */
    public function summariseDocumentConfig(string $configJson): array
    {
        $decoded = json_decode($configJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = ['doc_type' => 'html'];

        if (isset($decoded['html']) && is_string($decoded['html'])) {
            $out['html_length'] = mb_strlen($decoded['html']);
            // Count BOTH placeholder syntaxes the engine substitutes —
            // ${group.field|filter} (canonical) and {{group.field|filter}}
            // (back-compat) — with dotted keys + optional filter chains. Mirrors
            // DocumentFieldEngine::renderHtml so the timeline count matches what
            // actually gets rendered.
            $dollar = preg_match_all('/\$\{\s*([^}|]+(?:\|[^}]*)?)\s*\}/u', $decoded['html']);
            $curly  = preg_match_all('/\{\{\s*([^}|]+(?:\|[^}]*)?)\s*\}\}/u', $decoded['html']);
            $out['placeholder_count'] = (int) ($dollar ?: 0) + (int) ($curly ?: 0);
        }

        return $out;
    }
}
