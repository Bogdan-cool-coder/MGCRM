<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ChatMessageEvent;
use App\Models\Widget;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\WidgetDataService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

/**
 * WidgetTool — Prism toolset for the widget_generation chat type. The widget
 * mirror of ReportTool: it gives the AI a probe_data tool plus create_widget /
 * update_widget tools that build a Widget entity (an aggregating query + chart
 * presentation) instead of a Report (a dry table).
 *
 * Deliberate reuse (no copy-paste of machinery):
 *   - probe_data: the *exact same* tool definition ReportTool exposes
 *     (ReportTool::probeDataTool). The probe is read-only and entity-agnostic
 *     — it inspects MacroData, not the entity being built — so there is one
 *     probe contract shared between report and widget generation.
 *   - ConfigNormalizer: widget configs carry the same primary_model / where
 *     shape as report configs, so the canonical-name normalisation is reused
 *     unchanged (the unused report-only paths — columns/totals/filters — are
 *     simply absent from widget configs and ignored by the normaliser).
 *
 * Widget-specific (mirrored, not shared, because the diff is non-mechanical):
 *   - dry-run: ReportTool runs ReportDataService::getData($report, company,
 *     user, ...); widgets run WidgetDataService::compute($widget, company,
 *     period). Different signatures (no user, no per_page, period instead of
 *     pagination) and a different "is it broken" signal (compute() never
 *     throws on empty data — it returns an empty payload — so we treat a
 *     thrown exception OR a structurally-empty payload from a non-trivial
 *     config as a dry-run failure). Kept as its own helper here.
 *   - pre-validation: widgets require group_by + at least one aggregate +
 *     a chart{type,label_field,value_field}; there is no relation_aggregate /
 *     columns[].description validation (those are report concepts).
 *
 * Semantic-retry counter ($dryRunState) and event emitting (ChatEventEmitter)
 * follow the same per-turn contract as ReportTool — see getTools() and
 * ChatService::runForJob() for the "fresh state per turn" rationale.
 */
class WidgetTool
{
    public function __construct(
        protected ReportTool $reportTool,
        protected ConfigNormalizer $configNormalizer,
        protected WidgetDataService $widgetDataService,
    ) {}

    /**
     * Build the widget_generation toolset for a chat.
     *
     * @param  Chat  $chat
     * @param  object|null  $dryRunState  Per-turn mutable container with a public
     *                                    int `failures` property, shared between
     *                                    create_widget / update_widget closures
     *                                    so the semantic-retry counter is
     *                                    per-turn (not per-process). Mirrors
     *                                    ReportTool::getTools().
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
            $this->proposeWidgetVariantsTool($chat, $emitter),
            $this->createWidgetTool($chat, $dryRunState, $emitter),
            $this->updateWidgetTool($chat, $dryRunState, $emitter),
        ];
    }

    /**
     * Tool: propose_widget_variants — the FIRST step of the two-step widget
     * flow. Instead of creating a widget immediately, the AI proposes 2-4
     * candidate widget configs (different chart types, groupings, or metrics
     * for the same intent) and lets the user pick. This tool:
     *
     *   1. Decodes a JSON array of variant objects {label, config}.
     *   2. Normalizes each config via ConfigNormalizer + pre-validates its
     *      shape (the same gate create_widget uses). Invalid variants are
     *      dropped, not fatal — as long as ≥1 survives, we return what we have
     *      so the user still gets a choice.
     *   3. Does NOT write any Widget row and does NOT run a dry-run compute.
     *      Variants are ephemeral proposals; the frontend renders a live
     *      preview for each via POST /api/widgets/preview, and the actual
     *      compute only happens once on the chosen one at create_widget time.
     *   4. Emits a `widget_variants` event carrying the numbered, validated
     *      variants so the frontend timeline can render selectable preview
     *      cards.
     *
     * The selection loop: each variant is numbered (1-based `index`). When the
     * user picks one (frontend sends "вариант N" or the variant is otherwise
     * identified in a follow-up turn), the AI calls create_widget with that
     * variant's exact config — the configs are in this tool's result, which
     * lives in the conversation history. See WIDGETS_GUIDE §13.
     */
    protected function proposeWidgetVariantsTool(Chat $chat, ?ChatEventEmitter $emitter = null): Tool
    {
        $self = $this;

        return (new Tool)
            ->as('propose_widget_variants')
            ->for('Предложить пользователю 2-4 ВАРИАНТА виджета (разные типы чарта / группировки / метрики под один запрос) ВМЕСТО немедленного создания. Пользователь выберет один → потом ты вызовешь create_widget с конфигом выбранного. Параметр variants — JSON-массив объектов {label, config}. label — короткое название варианта ({"ru":"...","en":"..."} или строка). config — полный JSON-объект конфига виджета (тот же формат, что в create_widget). Виджеты НЕ создаются — это только предложения с превью.')
            ->withStringParameter('variants', 'JSON-массив из 2-4 объектов: [{"label":{"ru":"Сделки по статусам — кольцевая","en":"..."},"config":{...полный конфиг виджета...}}, {"label":"Топ-5 менеджеров","config":{...}}]. Каждый config — полноценный конфиг виджета (primary_model, group_by, aggregates, chart, ...), как в create_widget. Делай варианты осмысленно РАЗНЫМИ: другой chart.type (bar/pie/doughnut/line), другая группировка, top-N vs полный список, count vs sum.')
            ->using(function (string $variants) use ($chat, $emitter, $self): string {
                $resultJson = $self->runProposeWidgetVariants($variants, $chat, $emitter);
                return $resultJson;
            });
    }

    /**
     * @internal Public for the tool-closure $self access only. Treat as protected.
     */
    public function runProposeWidgetVariants(
        string $variants,
        Chat $chat,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $decoded = json_decode($variants, true);

            if (!is_array($decoded) || $decoded === [] || array_keys($decoded) !== range(0, count($decoded) - 1)) {
                return json_encode([
                    'success' => false,
                    'error'   => 'variants must be a non-empty JSON array of {label, config} objects.',
                    'hint'    => 'Передай 2-4 варианта: [{"label":"...","config":{...}}, ...].',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Cap at 4 — more than that is choice-overload on the frontend and
            // wastes tokens. Trim silently rather than erroring.
            $decoded = array_slice($decoded, 0, 4);

            $validVariants = [];
            $rejected      = [];
            $index         = 0;

            foreach ($decoded as $i => $variant) {
                if (!is_array($variant) || !isset($variant['config']) || !is_array($variant['config'])) {
                    $rejected[] = ['position' => $i, 'reason' => 'missing config object'];
                    continue;
                }

                $configArr = $variant['config'];

                $normResult = $this->configNormalizer->normalize($configArr);
                if (!$normResult['ok']) {
                    $rejected[] = ['position' => $i, 'reason' => 'normalization failed', 'errors' => $normResult['errors']];
                    continue;
                }
                $configArr = $normResult['config'];

                $primaryModel = $configArr['primary_model'] ?? null;
                if (!$primaryModel || !class_exists("App\\Models\\MacroData\\{$primaryModel}")) {
                    $rejected[] = ['position' => $i, 'reason' => "model not found: " . (is_string($primaryModel) ? $primaryModel : '<missing>')];
                    continue;
                }

                $shapeErrors = $this->prevalidateWidgetShape($configArr);
                if (!empty($shapeErrors)) {
                    $rejected[] = ['position' => $i, 'reason' => 'invalid shape', 'errors' => $shapeErrors];
                    continue;
                }

                $index++;
                $validVariants[] = [
                    'index'  => $index,
                    'label'  => $this->normaliseVariantLabel($variant['label'] ?? null, $index),
                    'config' => $configArr,
                ];
            }

            if ($validVariants === []) {
                return json_encode([
                    'success'  => false,
                    'error'    => 'No valid variants — all candidates failed normalization / shape validation.',
                    'rejected' => $rejected,
                    'hint'     => 'Исправь конфиги вариантов (group_by + aggregates + chart, плоский where) и вызови propose_widget_variants снова.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Emit the variants event so the frontend can render preview cards.
            // Payload mirrors the tool result so SSE-stream and reload-replay
            // see the same structured shape.
            if ($emitter !== null) {
                try {
                    $emitter->emit(ChatMessageEvent::TYPE_WIDGET_VARIANTS, [
                        'variants' => $validVariants,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('WidgetTool: failed to emit widget_variants event', [
                        'chat_id' => $chat->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $response = [
                'success'        => true,
                'proposed'       => true,
                'variants_count' => count($validVariants),
                'variants'       => $validVariants,
                'hint'           => 'Варианты предложены пользователю. НЕ создавай виджет сейчас. Жди, пока пользователь выберет вариант (например «вариант 2»), затем вызови create_widget с config выбранного варианта (скопируй его как есть из этого результата).',
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
     * Normalise a variant label into a short human string. Accepts a localized
     * {ru, en} object or a bare string; falls back to "Вариант N".
     */
    protected function normaliseVariantLabel(mixed $label, int $index): string
    {
        if (is_array($label)) {
            foreach (['ru', 'en'] as $loc) {
                if (isset($label[$loc]) && is_string($label[$loc]) && $label[$loc] !== '') {
                    return mb_substr($label[$loc], 0, 120);
                }
            }
        } elseif (is_string($label) && $label !== '') {
            return mb_substr($label, 0, 120);
        }

        return "Вариант {$index}";
    }

    /**
     * Tool: create_widget — AI creates a new widget.
     *
     * Flow mirrors create_report:
     *   1. Decode name + config JSON.
     *   2. Normalize via ConfigNormalizer (snake_case → canonical model name).
     *   3. Pre-validate the widget shape (group_by + aggregates + chart).
     *   4. Save the Widget (company_id / user_id from the chat).
     *   5. Pin chat.widget_id + widget.chat_message_id (decision N4).
     *   6. Dry-run via WidgetDataService::compute(); on failure tag
     *      widget.metadata.dry_run_failed=true and run the semantic-retry
     *      escalation.
     */
    protected function createWidgetTool(Chat $chat, object $dryRunState, ?ChatEventEmitter $emitter = null): Tool
    {
        $self = $this;

        return (new Tool)
            ->as('create_widget')
            ->for('Создать новый виджет — маленькую агрегированную таблицу под один чарт. Параметры — JSON строки. name: {"ru":"...","en":"..."}. config: JSON конфиг виджета с primary_model, group_by, aggregates, chart. См. секцию формата виджета в системном промпте.')
            ->withStringParameter('name', 'JSON строка: {"ru":"Название","en":"Title"}')
            ->withStringParameter('config', 'Полный JSON конфиг виджета: {"primary_model":"...","where":[...],"group_by":{"fields":[...]},"aggregates":[{"field":"...","fn":"sum","as":"value"}],"chart":{"type":"bar","label_field":"...","value_field":"...","limit":10,"others_label":"Другие","label":"..."},"period_field":"..."}. group_by/label_field могут быть: bare-поле, relation dot-path (usersManager.users_name — группировка по ИМЕНИ, не по id) или temporal-токен (deal_date|month — динамика по времени). where — ТОЛЬКО плоские условия (без whereHas / relations). chart.limit+others_label — топ-N для измерений с многими категориями. period_field — обязателен для temporal/line виджетов (имя date-колонки).')
            ->using(function (string $name, string $config) use ($chat, $dryRunState, $emitter, $self): string {
                $namePreview = $self->reportTool->summariseTitle($name);
                $configSummary = $self->summariseWidgetConfig($config);
                $self->reportTool->emitToolCall($emitter, 'create_widget', array_merge(
                    ['name' => $namePreview],
                    $configSummary,
                ));

                $resultJson = $self->runCreateWidget($name, $config, $chat, $dryRunState, $emitter);
                $self->reportTool->emitToolResultFromJson($emitter, 'create_widget', $resultJson);
                return $resultJson;
            });
    }

    /**
     * @internal Public for the tool-closure $self access only. Treat as protected.
     */
    public function runCreateWidget(
        string $name,
        string $config,
        Chat $chat,
        object $dryRunState,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $nameArr = json_decode($name, true);
            $configArr = json_decode($config, true);

            if (!$nameArr || !$configArr) {
                return json_encode(['error' => 'Invalid JSON in name or config']);
            }

            $normResult = $this->configNormalizer->normalize($configArr);
            if (!$normResult['ok']) {
                return json_encode([
                    'error'  => 'Config normalization failed',
                    'errors' => $normResult['errors'],
                    'hint'   => 'primary_model должен быть PascalCase (например EstateDeals). Виджет использует ТОЛЬКО плоский primary_model + поля; relation-сегменты (точки) в widget config не поддерживаются.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $configArr = $normResult['config'];

            $primaryModel = $configArr['primary_model'] ?? null;
            if (!$primaryModel) {
                return json_encode(['error' => 'primary_model is required in config']);
            }

            $modelClass = "App\\Models\\MacroData\\{$primaryModel}";
            if (!class_exists($modelClass)) {
                return json_encode(['error' => "Model not found: {$primaryModel}"]);
            }

            $shapeErrors = $this->prevalidateWidgetShape($configArr);
            if (!empty($shapeErrors)) {
                return json_encode([
                    'success' => false,
                    'errors'  => $shapeErrors,
                    'hint'    => 'Виджет требует: group_by.fields[] (минимум одно поле), aggregates[] (минимум одна агрегация с fn ∈ count/sum/avg/min/max), и chart{type ∈ bar/line/pie/doughnut, label_field, value_field}. where — только плоские условия. order_by — только поля из group_by или alias агрегата.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (!empty($normResult['changes'])) {
                Log::info('AI widget config normalized', [
                    'chat_id' => $chat->id,
                    'tool'    => 'create_widget',
                    'changes' => $normResult['changes'],
                ]);
            }

            $widget = Widget::create([
                'name'         => $nameArr,
                'config'       => $configArr,
                'is_system'    => false,
                'is_published' => false,
                'user_id'      => $chat->user_id,
                'company_id'   => $chat->company_id,
            ]);

            // Decision N4: pin BOTH chat.widget_id and (after we know the
            // assistant message id) widget.chat_message_id. The chat_message_id
            // back-link is set in ChatService::runForJob() — same lifecycle as
            // report.chat_message_id — because the assistant message row is the
            // canonical "message that created this entity".
            $chat->update(['widget_id' => $widget->id]);

            return $this->runDryRunAndBuildResponse(
                $widget,
                $chat,
                $dryRunState,
                normalizedChanges: $normResult['changes'],
                tool: 'create_widget',
                created: true,
                emitter: $emitter,
            );
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Tool: update_widget — AI updates the config of the chat's pinned widget.
     *
     * Fallback (mirrors update_report → create_report): if the chat has no
     * widget pinned yet, this is a no-op error telling the AI to create_widget
     * first. (We don't auto-create here so the AI's intent stays explicit.)
     */
    protected function updateWidgetTool(Chat $chat, object $dryRunState, ?ChatEventEmitter $emitter = null): Tool
    {
        $self = $this;

        return (new Tool)
            ->as('update_widget')
            ->for('Обновить конфиг существующего виджета (того, что привязан к чату). Параметр — JSON строка с ПОЛНЫМ обновлённым конфигом виджета.')
            ->withStringParameter('config', 'Полный обновлённый JSON конфиг виджета: {"primary_model":"...","group_by":{"fields":[...]},"aggregates":[...],"chart":{...},"period_field":"..."}. where — только плоские условия.')
            ->using(function (string $config) use ($chat, $dryRunState, $emitter, $self): string {
                $configSummary = $self->summariseWidgetConfig($config);
                $callPayload = ['widget_id' => $chat->widget_id ?? null] + $configSummary;
                $self->reportTool->emitToolCall($emitter, 'update_widget', $callPayload);

                $resultJson = $self->runUpdateWidget($config, $chat, $dryRunState, $emitter);
                $self->reportTool->emitToolResultFromJson($emitter, 'update_widget', $resultJson);
                return $resultJson;
            });
    }

    /**
     * @internal Public for the tool-closure $self access only. Treat as protected.
     */
    public function runUpdateWidget(
        string $config,
        Chat $chat,
        object $dryRunState,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $widget = $chat->widget;

            if (!$widget) {
                return json_encode(['error' => 'No widget linked to this chat. Use create_widget first.']);
            }

            $configArr = json_decode($config, true);
            if (!$configArr) {
                return json_encode(['error' => 'Invalid JSON in config']);
            }

            $normResult = $this->configNormalizer->normalize($configArr);
            if (!$normResult['ok']) {
                return json_encode([
                    'error'  => 'Config normalization failed',
                    'errors' => $normResult['errors'],
                    'hint'   => 'primary_model должен быть PascalCase (например EstateDeals). Виджет использует только плоский primary_model + поля.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $configArr = $normResult['config'];

            $primaryModel = $configArr['primary_model'] ?? null;
            if ($primaryModel) {
                $modelClass = "App\\Models\\MacroData\\{$primaryModel}";
                if (!class_exists($modelClass)) {
                    return json_encode(['error' => "Model not found: {$primaryModel}"]);
                }
            }

            $shapeErrors = $this->prevalidateWidgetShape($configArr);
            if (!empty($shapeErrors)) {
                return json_encode([
                    'success' => false,
                    'errors'  => $shapeErrors,
                    'hint'    => 'Виджет требует: group_by.fields[], aggregates[], chart{type, label_field, value_field}. where — только плоские условия.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (!empty($normResult['changes'])) {
                Log::info('AI widget config normalized', [
                    'chat_id' => $chat->id,
                    'tool'    => 'update_widget',
                    'changes' => $normResult['changes'],
                ]);
            }

            $widget->update(['config' => $configArr]);

            return $this->runDryRunAndBuildResponse(
                $widget,
                $chat,
                $dryRunState,
                normalizedChanges: $normResult['changes'],
                tool: 'update_widget',
                created: false,
                emitter: $emitter,
            );
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Pre-validation
    // -------------------------------------------------------------------------

    /**
     * Validate the widget config shape before it reaches WidgetDataService.
     * WidgetDataService silently returns an empty payload when group_by /
     * aggregates / chart are missing — which would leave the user with a blank
     * widget and no signal. We reject those cases at tool-level so the AI gets
     * an actionable error within the same turn.
     *
     * Enforced (a strict subset of what WidgetDataService::compute reads):
     *   - group_by.fields[] — non-empty list of group tokens. A group token is
     *                         (a) a bare identifier, (b) a single-hop relation
     *                         dot-path "relation.column", or (c) a temporal token
     *                         "<date_field>|<granularity>" with granularity in
     *                         month|year|day|week. Multi-hop chains ("a.b.c") and
     *                         unknown granularities are rejected.
     *   - aggregates[]      — at least one entry with fn ∈ count/sum/avg/min/max;
     *                         non-count aggregates require a bare `field` (the
     *                         aggregate must be over a primary-table column — no
     *                         dot-paths / temporal tokens in aggregates).
     *   - chart.type        — bar | line | pie | doughnut.
     *   - chart.label_field — a group token (mirrors group_by — typically equals a
     *                         group_by field).
     *   - chart.value_field — bare identifier (an aggregate alias — never a dot-path).
     *   - chart.limit       — when present, a positive integer (top-N truncation).
     *   - chart.others_label— when present, a non-empty string (remainder bucket).
     *   - order_by[].field  — a group token matching a group_by field, or an
     *                         aggregate alias.
     *   - where[]           — FLAT only: no `type: whereHas`, no `relation` key.
     *   - period_field      — when present, a bare identifier.
     *
     * Why dot-paths / temporal tokens are allowed here but NOT in aggregates / where:
     * WidgetDataService resolves a single-hop relation dot-path (→ JOIN alias) or a
     * temporal token (→ DATE_FORMAT bucket) in group_by / label_field / order_by so
     * charts can be grouped by the related entity's *name* (e.g. "usersManager.users_name")
     * or by a time bucket (e.g. "deal_date|month") instead of an opaque FK id / raw
     * timestamp. Aggregates stay on the primary table; where stays FLAT.
     *
     * @param  array<string, mixed>  $configArr
     * @return list<array{type: string, message: string}>
     */
    protected function prevalidateWidgetShape(array $configArr): array
    {
        $errors = [];
        $isBareIdent = static fn ($v): bool => is_string($v) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $v) === 1;
        // A group_by / label_field / order_by token: one of three forms that
        // WidgetDataService::compute() understands for an axis / legend dimension:
        //   a) bare identifier            "deal_status"
        //   b) single-hop relation path   "usersManager.users_name"  (exactly one
        //      dot — multi-hop "a.b.c" is rejected; engine does one JOIN hop)
        //   c) temporal token             "deal_date|month"          (date column
        //      bucketed by month|year|day|week — for time-series / dynamics charts)
        // Anything else (multi-hop, unknown granularity, garbage) falls through to
        // false and produces an actionable error.
        $isTemporalToken = static fn ($v): bool => is_string($v)
            && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\|(month|year|day|week)$/', $v) === 1;
        $isGroupToken = static function ($v) use ($isBareIdent, $isTemporalToken): bool {
            if ($isBareIdent($v)) {
                return true;
            }
            if ($isTemporalToken($v)) {
                return true;
            }
            return is_string($v) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $v) === 1;
        };

        // group_by.fields[]
        $groupFields = $configArr['group_by']['fields'] ?? null;
        if (!is_array($groupFields) || $groupFields === []) {
            $errors[] = [
                'type'    => 'missing_group_by',
                'message' => 'group_by.fields[] is required and must list at least one field for the chart axis / legend.',
            ];
        } else {
            foreach ($groupFields as $gf) {
                if (!$isGroupToken($gf)) {
                    $errors[] = [
                        'type'    => 'invalid_group_field',
                        'message' => "group_by field '" . (is_string($gf) ? $gf : gettype($gf)) . "' must be a bare identifier (e.g. deal_status), a single-hop relation dot-path (e.g. usersManager.users_name), or a temporal token <date_field>|<granularity> with granularity in month|year|day|week (e.g. deal_date|month). Multi-hop chains are not supported.",
                    ];
                }
            }
        }

        // aggregates[]
        $aggregates = $configArr['aggregates'] ?? null;
        $aggregateAliases = [];
        if (!is_array($aggregates) || $aggregates === []) {
            $errors[] = [
                'type'    => 'missing_aggregates',
                'message' => 'aggregates[] is required and must contain at least one aggregation, e.g. {"field":"deal_sum","fn":"sum","as":"value"}.',
            ];
        } else {
            $allowedFns = ['count', 'sum', 'avg', 'min', 'max'];
            foreach ($aggregates as $idx => $agg) {
                if (!is_array($agg)) {
                    $errors[] = ['type' => 'invalid_aggregate', 'message' => "aggregates[{$idx}] must be an object."];
                    continue;
                }
                $fn = is_string($agg['fn'] ?? null) ? strtolower(trim($agg['fn'])) : null;
                if (!in_array($fn, $allowedFns, true)) {
                    $errors[] = [
                        'type'    => 'invalid_aggregate_fn',
                        'message' => "aggregates[{$idx}].fn must be one of count, sum, avg, min, max.",
                    ];
                    continue;
                }
                if ($fn !== 'count' && !$isBareIdent($agg['field'] ?? null)) {
                    $errors[] = [
                        'type'    => 'invalid_aggregate_field',
                        'message' => "aggregates[{$idx}] with fn='{$fn}' requires a bare identifier `field`.",
                    ];
                }
                $alias = $agg['as'] ?? ($agg['field'] ?? null);
                if (is_string($alias) && $alias !== '') {
                    $aggregateAliases[] = $alias;
                }
            }
        }

        // chart{}
        $chart = $configArr['chart'] ?? null;
        if (!is_array($chart)) {
            $errors[] = [
                'type'    => 'missing_chart',
                'message' => 'chart{type, label_field, value_field} is required.',
            ];
        } else {
            $allowedTypes = ['bar', 'line', 'pie', 'doughnut'];
            $type = is_string($chart['type'] ?? null) ? strtolower(trim($chart['type'])) : null;
            if (!in_array($type, $allowedTypes, true)) {
                $errors[] = [
                    'type'    => 'invalid_chart_type',
                    'message' => 'chart.type must be one of bar, line, pie, doughnut.',
                ];
            }
            if (!$isGroupToken($chart['label_field'] ?? null)) {
                $errors[] = [
                    'type'    => 'invalid_label_field',
                    'message' => 'chart.label_field must equal a group_by field — a bare identifier, a single-hop relation dot-path (e.g. usersManager.users_name), or a temporal token (e.g. deal_date|month).',
                ];
            }

            // chart.limit (top-N) — optional positive integer. chart.others_label
            // (the "rest" bucket label) — optional non-empty string. Both are
            // passed through to WidgetDataService::applyTopN(). Reject obviously
            // wrong shapes so the AI gets a signal instead of a silently-ignored key.
            if (array_key_exists('limit', $chart) && $chart['limit'] !== null) {
                $limit = $chart['limit'];
                if (!is_int($limit) || $limit < 1) {
                    $errors[] = [
                        'type'    => 'invalid_chart_limit',
                        'message' => 'chart.limit must be a positive integer (top-N) or omitted. Use it on high-cardinality dimensions (channels, cities) — e.g. "limit": 10.',
                    ];
                }
            }
            if (array_key_exists('others_label', $chart) && $chart['others_label'] !== null) {
                if (!is_string($chart['others_label']) || trim($chart['others_label']) === '') {
                    $errors[] = [
                        'type'    => 'invalid_others_label',
                        'message' => 'chart.others_label must be a non-empty string (the bucket label for the remainder after top-N, e.g. "Другие") or omitted. Requires chart.limit to take effect.',
                    ];
                }
            }
            if (!$isBareIdent($chart['value_field'] ?? null)) {
                $errors[] = [
                    'type'    => 'invalid_value_field',
                    'message' => 'chart.value_field must be a bare identifier (typically an aggregate alias).',
                ];
            }
        }

        // where[] — FLAT only.
        foreach (($configArr['where'] ?? []) as $i => $cond) {
            if (!is_array($cond)) {
                continue;
            }
            if (($cond['type'] ?? null) === 'whereHas' || array_key_exists('relation', $cond)) {
                $errors[] = [
                    'type'    => 'unsupported_where',
                    'message' => "where[{$i}] uses a relational condition (whereHas / relation). WidgetDataService supports FLAT conditions only — filter on primary_model fields directly.",
                ];
            }
        }

        // order_by[] — only group_by fields or aggregate aliases.
        if (empty($errors) && is_array($configArr['order_by'] ?? null)) {
            $allowedOrder = array_merge(
                is_array($groupFields) ? $groupFields : [],
                $aggregateAliases,
            );
            foreach ($configArr['order_by'] as $i => $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $field = $spec['field'] ?? null;
                if (!in_array($field, $allowedOrder, true)) {
                    $errors[] = [
                        'type'    => 'invalid_order_by',
                        'message' => "order_by[{$i}].field '" . (is_string($field) ? $field : gettype($field)) . "' must be one of the group_by fields or an aggregate alias.",
                    ];
                }
            }
        }

        // period_field — bare identifier when present.
        if (array_key_exists('period_field', $configArr) && $configArr['period_field'] !== null) {
            if (!$isBareIdent($configArr['period_field'])) {
                $errors[] = [
                    'type'    => 'invalid_period_field',
                    'message' => 'period_field must be a bare date-column identifier (no dots) or null.',
                ];
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // Dry-run
    // -------------------------------------------------------------------------

    /**
     * Run the widget dry-run via WidgetDataService::compute() and build the
     * tool JSON response. Mirrors ReportTool::runDryRunAndBuildResponse but
     * tuned to the widget compute contract.
     *
     * compute() does not throw on empty data — it returns an empty payload.
     * So a dry-run "failure" here is either:
     *   - a thrown exception (model boot / query build problem), OR
     *   - an empty `datasets` array (compute() bailed early because the config
     *     was structurally unusable — bad group_by / aggregates / chart that
     *     slipped past pre-validation, or an unresolved $company_var).
     * Either way we tag the widget and run the semantic-retry escalation.
     */
    protected function runDryRunAndBuildResponse(
        Widget $widget,
        Chat $chat,
        object $dryRunState,
        array $normalizedChanges,
        string $tool,
        bool $created,
        ?ChatEventEmitter $emitter = null,
    ): string {
        $dryRunEnabled = (bool) config('ai.dry_run.enabled', true);

        if (!$dryRunEnabled) {
            return $this->buildSuccessResponse($widget, $created, $normalizedChanges, samplePreview: null);
        }

        $company = $chat->company;
        if (!$company) {
            return $this->buildSuccessResponse($widget, $created, $normalizedChanges, samplePreview: null);
        }

        $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_START, [
            'widget_id' => $widget->id,
            'tool'      => $tool,
        ]);

        $startedAt = microtime(true);

        try {
            $payload = $this->widgetDataService->compute($widget->fresh(), $company);

            $datasets = $payload['datasets'] ?? [];
            $rowCount = $payload['meta']['row_count'] ?? 0;

            if (empty($datasets)) {
                // Structurally-empty result — compute() bailed. Treat as a
                // dry-run failure so the AI fixes the config rather than
                // shipping a blank widget.
                $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                    'widget_id' => $widget->id,
                    'tool'      => $tool,
                    'success'   => false,
                    'ms'        => (int) round((microtime(true) - $startedAt) * 1000),
                    'message'   => 'compute() returned an empty dataset (unusable widget config)',
                ]);

                return $this->handleDryRunFailure(
                    $widget,
                    $chat,
                    $dryRunState,
                    $tool,
                    new \RuntimeException('WidgetDataService::compute() returned an empty dataset — the config produced no chart series. Check group_by / aggregates / chart fields and that primary_model fields exist.'),
                    $emitter,
                );
            }

            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'widget_id' => $widget->id,
                'tool'      => $tool,
                'success'   => true,
                'ms'        => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // A tiny preview: labels + first dataset data, capped.
            $preview = [
                'labels'   => array_slice($payload['labels'] ?? [], 0, 5),
                'data'     => array_slice($datasets[0]['data'] ?? [], 0, 5),
                'row_count' => (int) $rowCount,
            ];

            return $this->buildSuccessResponse($widget, $created, $normalizedChanges, samplePreview: $preview);
        } catch (\Throwable $e) {
            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'widget_id'       => $widget->id,
                'tool'            => $tool,
                'success'         => false,
                'ms'              => (int) round((microtime(true) - $startedAt) * 1000),
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            return $this->handleDryRunFailure($widget, $chat, $dryRunState, $tool, $e, $emitter);
        }
    }

    /**
     * Build the success-shaped JSON the widget tool returns when dry-run passes.
     *
     * @param  list<array>  $normalizedChanges
     * @param  array<string, mixed>|null  $samplePreview
     */
    protected function buildSuccessResponse(
        Widget $widget,
        bool $created,
        array $normalizedChanges,
        ?array $samplePreview,
    ): string {
        $response = [
            'success'   => true,
            'widget_id' => $widget->id,
        ];

        if ($created) {
            $response['url']     = "/api/widgets/{$widget->id}";
            $response['created'] = true;
        } else {
            $response['updated'] = true;
        }

        if (!empty($normalizedChanges)) {
            $response['normalized_changes'] = $normalizedChanges;
        }

        if ($samplePreview !== null) {
            $response['preview'] = $samplePreview;
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle a widget dry-run failure: tag widget.metadata.dry_run_failed,
     * bump the per-turn counter, and escalate the hint from "try again" to
     * "stop trying" once max_semantic_retries is exceeded. Mirrors
     * ReportTool::handleDryRunFailure.
     */
    protected function handleDryRunFailure(
        Widget $widget,
        Chat $chat,
        object $dryRunState,
        string $tool,
        \Throwable $e,
        ?ChatEventEmitter $emitter = null,
    ): string {
        Log::warning('WidgetTool dry-run failed', [
            'chat_id'         => $chat->id,
            'widget_id'       => $widget->id,
            'tool'            => $tool,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
        ]);

        try {
            $existing = $widget->metadata ?? [];
            $widget->update([
                'metadata' => array_merge($existing, [
                    'dry_run_failed' => true,
                    'dry_run_error'  => [
                        'exception_class' => get_class($e),
                        'message'         => $e->getMessage(),
                        'tool'            => $tool,
                        'at'              => now()->toIso8601String(),
                    ],
                ]),
            ]);
        } catch (\Throwable $tagErr) {
            Log::warning('WidgetTool: could not tag widget.metadata.dry_run_failed', [
                'widget_id' => $widget->id,
                'error'     => $tagErr->getMessage(),
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
            'tool'      => $tool,
        ]);

        $hint = $exhausted
            ? "This is dry-run failure #{$dryRunState->failures} in a row "
                . "(limit: {$maxRetries}). STOP trying to create or update the widget "
                . 'automatically. In your next reply, do NOT call any tool. Explain to '
                . 'the user that you could not build a working widget config and ask '
                . 'them to refine the request (which model, which grouping, which metric).'
            : 'Widget row was saved (kept as debug artefact) but data compute via '
                . 'WidgetDataService::compute() failed or produced an empty chart. Try a '
                . 'simpler config: a single group_by field, one count/sum aggregate, '
                . 'plain primary_model fields, FLAT where only. If you cannot find a '
                . 'working config quickly, stop and ask the user.';

        $payload = [
            'success'   => false,
            'widget_id' => $widget->id,
            'errors'    => [[
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
     * Pluck a short structural summary from the JSON-stringified widget config
     * for the tool_call event. Best-effort — never throws.
     *
     * @return array<string, mixed>
     * @internal Public for the tool-closure $self access only.
     */
    public function summariseWidgetConfig(string $configJson): array
    {
        $decoded = json_decode($configJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        if (isset($decoded['primary_model']) && is_string($decoded['primary_model'])) {
            $out['primary_model'] = $decoded['primary_model'];
        }
        if (isset($decoded['chart']['type']) && is_string($decoded['chart']['type'])) {
            $out['chart_type'] = $decoded['chart']['type'];
        }
        $groupFields = $decoded['group_by']['fields'] ?? null;
        if (is_array($groupFields)) {
            $out['group_by_count'] = count($groupFields);
        }

        return $out;
    }
}
