<?php

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ChatMessageEvent;
use App\Models\Report;
use App\Services\MacroData\ConfigNormalizer;
use App\Services\MacroData\ReportDataService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

class ReportTool
{
    public function __construct(
        protected DataProbeService $dataProbeService,
        protected ConfigNormalizer $configNormalizer,
        protected ReportDataService $reportDataService,
    ) {}

    /**
     * Get all tools for report generation.
     *
     * @param Chat $chat
     * @param object|null $dryRunState  Per-call state container shared between
     *                                  create_report / update_report closures.
     *                                  Must expose a mutable public int property
     *                                  `failures`. ChatService creates one fresh
     *                                  state per sendMessage() call so the
     *                                  semantic-retry counter is per-turn, not
     *                                  per-process. Tests can pass their own.
     * @param ChatEventEmitter|null $emitter  Optional event emitter for the
     *                                  async streaming pipeline (M4). When
     *                                  provided, create_report / update_report
     *                                  emit dry_run_start / dry_run_result /
     *                                  retry events as they run. Null in the
     *                                  sync sendMessage() path — those callers
     *                                  do not need streaming.
     * @return Tool[]
     */
    public function getTools(Chat $chat, ?object $dryRunState = null, ?ChatEventEmitter $emitter = null): array
    {
        // quick_qa mode: probe_data + query_data for answering questions
        if ($chat->type === 'quick_qa') {
            return [
                $this->probeDataTool($chat, $emitter),
                $this->probeCustomAttributesTool($chat, $emitter),
                $this->queryDataTool($chat, $emitter),
            ];
        }

        // report_generation mode: full toolset for creating/updating reports.
        // Counter lives outside the closures so the two report tools share it
        // across Prism's tool-call loop within a single sendMessage() turn.
        $dryRunState ??= (object) ['failures' => 0];

        return [
            $this->probeDataTool($chat, $emitter),
            $this->probeCustomAttributesTool($chat, $emitter),
            $this->createReportTool($chat, $dryRunState, $emitter),
            $this->updateReportTool($chat, $dryRunState, $emitter),
        ];
    }

    /**
     * Tool: probe_data — AI probes MacroData before generating config.
     *
     * The emitter (when provided) writes a `tool_call` event right before the
     * probe runs and a `tool_result` event after — short sanitized summaries
     * only (model + field list, then row_count + fields_count). No sample rows
     * leak through (those carry real data). See emitToolCall / emitToolResult
     * helpers for the payload contract.
     *
     * The emitter is intentionally NULL when the underlying provider streams
     * tool events natively (Anthropic): ChatService swaps it out so the
     * stream-level callback fires instead, preventing double-emit. See the
     * `closuresEmitToolEvents` flag in runForJob().
     *
     * Public so WidgetTool can reuse the exact same probe_data tool definition
     * (same DataProbeService::probe call, same event-emit shape) for the
     * widget_generation toolset instead of duplicating it. The probe is
     * read-only and entity-agnostic — it inspects MacroData, not reports — so
     * sharing it keeps a single source of truth for the probe contract.
     */
    public function probeDataTool(Chat $chat, ?ChatEventEmitter $emitter = null): Tool
    {
        $dataProbeService = $this->dataProbeService;
        $company = $chat->company;
        $self = $this;

        return (new Tool)
            ->as('probe_data')
            ->for('Проверить реальные данные в MacroData перед генерацией отчёта. Возвращает sample строк, общее количество и статистику полей.')
            ->withStringParameter('model', 'Короткое имя модели MacroData (например EstateDeals, EstateSells)')
            ->withArrayParameter(
                'fields',
                'Список полей для выборки (пустой = все)',
                new StringSchema('field', 'Имя поля'),
                false,
            )
            ->withArrayParameter(
                'relations',
                'Список связей для eager loading (например ["estateSells", "estateSells.estateHouses"])',
                new StringSchema('relation', 'Имя связи'),
                false,
            )
            ->using(function (string $model, ?array $fields = null, ?array $relations = null) use ($dataProbeService, $company, $emitter, $self): string {
                $self->emitToolCall($emitter, 'probe_data', [
                    'model'  => $model,
                    'fields' => is_array($fields) ? array_values($fields) : [],
                ]);

                try {
                    $result = $dataProbeService->probe(
                        $company,
                        $model,
                        $fields ?? [],
                        $relations ?? [],
                    );

                    $self->emitToolResult($emitter, 'probe_data', true, [
                        'rows_count'   => is_array($result['sample_rows'] ?? null) ? count($result['sample_rows']) : 0,
                        'total_count'  => (int) ($result['row_count'] ?? 0),
                        'fields_count' => is_array($result['sample_rows'][0] ?? null) ? count($result['sample_rows'][0]) : 0,
                    ]);

                    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } catch (\Throwable $e) {
                    $self->emitToolResult($emitter, 'probe_data', false, [
                        'error' => $e->getMessage(),
                    ]);

                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    /**
     * Tool: probe_custom_attributes — enumerate the EAV / custom attributes
     * available for a MACRO entity (estate_sell / estate_deal / contacts / ...).
     *
     * MACRO stores admin-defined "custom columns" (balcony / terrace area,
     * nationality, condition, custom status, ...) in EAV side-tables, NOT in the
     * main model columns. A flat probe_data of EstateSells / EstateDeals never
     * surfaces them. This tool lists what custom fields actually exist for the
     * current client so the AI can decide whether a requested column is real
     * before declaring it "unavailable".
     *
     * Returns:
     *   - custom_attributes      — from estate_attributes (key = attr_id, title)
     *   - builtin_sell_attributes — from estate_sells_attr (key = attr_name)
     *
     * Both kinds are surfaceable as a report column via the dedicated
     * `custom_attribute` column type (correlated subquery; works even when the
     * primary model reaches the EAV table through a BelongsTo). See the EAV
     * section of REPORTS_GUIDE.md for the exact column config shape.
     *
     * Read-only. The emitter records a tool_call + tool_result with attribute
     * counts only (no values leak through).
     *
     * Public so WidgetTool can reuse the same definition if needed.
     */
    public function probeCustomAttributesTool(Chat $chat, ?ChatEventEmitter $emitter = null): Tool
    {
        $dataProbeService = $this->dataProbeService;
        $company = $chat->company;
        $self = $this;

        return (new Tool)
            ->as('probe_custom_attributes')
            ->for('Перечислить кастомные / EAV-атрибуты MACRO для сущности (estate_sell, estate_deal, contacts, estate_buy, promos). '
                . 'Эти поля (балкон, терраса, гражданство, состояние, кастомные статусы и т.п.) живут в отдельных EAV-таблицах, '
                . 'а обычный probe_data их НЕ показывает. ВСЕГДА вызывай этот инструмент, когда пользователь просит колонку, '
                . 'которой нет среди прямых полей модели, ПЕРЕД тем как заявить «такого поля нет». Read-only.')
            ->withStringParameter('entity', 'Сущность: estate_sell (объекты, по умолчанию), estate_deal (сделки), contacts (контрагенты), estate_buy (заявки), promos (акции)', false)
            ->using(function (?string $entity = null) use ($dataProbeService, $company, $emitter, $self): string {
                $resolvedEntity = ($entity !== null && $entity !== '') ? $entity : 'estate_sell';

                $self->emitToolCall($emitter, 'probe_custom_attributes', [
                    'entity' => $resolvedEntity,
                ]);

                try {
                    $result = $dataProbeService->probeCustomAttributes($company, $resolvedEntity);

                    $self->emitToolResult($emitter, 'probe_custom_attributes', true, [
                        'custom_count'  => is_array($result['custom_attributes'] ?? null) ? count($result['custom_attributes']) : 0,
                        'builtin_count' => is_array($result['builtin_sell_attributes'] ?? null) ? count($result['builtin_sell_attributes']) : 0,
                    ]);

                    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } catch (\Throwable $e) {
                    // Diagnosability (prod incident 2026-05-28): the closure used
                    // to swallow the cause silently — only a tool_result event
                    // carried the message, which is invisible in the worker log.
                    // Log the full cause + context so a post-mortem can tell
                    // WHY the EAV probe failed (MacroData connection down, a
                    // client schema missing estate_attributes / estate_sells_attr,
                    // bad entity, SQL error) without re-running the turn.
                    Log::error('probe_custom_attributes failed', [
                        'company_id' => $company?->id,
                        'entity'     => $resolvedEntity,
                        'exception'  => get_class($e),
                        'message'    => $e->getMessage(),
                    ]);

                    // Keep the error in the tool_result payload (already the
                    // behaviour) so the live timeline shows it too — but cap the
                    // length so a giant SQL dump can't bloat the event row.
                    $self->emitToolResult($emitter, 'probe_custom_attributes', false, [
                        'error' => \Illuminate\Support\Str::limit($e->getMessage(), 300),
                    ]);

                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    /**
     * Tool: query_data — Execute filtered queries with aggregation.
     *
     * Two schema variants depending on chat type:
     *   - quick_qa: extended schema with group_by + order_by + limit, allowing
     *     AI to compute "top N managers by sales" style answers in one call.
     *     Plus rate-limit (20 calls/min per chat) tracked in chat.ai_context.
     *   - report_generation: this tool is not currently registered for that
     *     mode (see getTools()), so this branch is unused there. If it ever
     *     gets wired in, it will inherit the same schema by default.
     *
     * The grouped variant returns rows[]; the ungrouped variant returns a
     * scalar aggregate. AI is responsible for picking the right shape based
     * on the user question.
     */
    protected function queryDataTool(Chat $chat, ?ChatEventEmitter $emitter = null): Tool
    {
        $dataProbeService = $this->dataProbeService;
        $company = $chat->company;
        $isQuickQa = $chat->type === 'quick_qa';
        $self = $this;

        $description = $isQuickQa
            ? 'Выполнить фильтрованный запрос с агрегацией, опционально с группировкой. '
                . 'Используй для ответов на вопросы типа: '
                . '"сколько сделок в апреле?" (без group_by), '
                . '"сумма продаж по менеджерам в апреле?" (group_by=[user_id]), '
                . '"топ-5 ЖК по сделкам" (group_by + order_by по aggregate + limit=5). '
                . 'Все параметры кроме model и aggregate — JSON строки.'
            : 'Выполнить фильтрованный запрос с агрегацией. Используй для ответов на вопросы типа '
                . '"сколько сделок в апреле?", "средняя сумма сделок за 2024 год?".';

        $tool = (new Tool)
            ->as('query_data')
            ->for($description)
            ->withStringParameter('model', 'Короткое имя модели MacroData (например EstateDeals, EstateSells)')
            ->withStringParameter('aggregate', 'Тип агрегации: count (количество), sum (сумма), avg (среднее), min (минимум), max (максимум)')
            ->withStringParameter('field', 'Поле для агрегации (обязательно для sum/avg/min/max, игнорируется для count)', false)
            ->withStringParameter('filters', 'JSON массив условий фильтрации: [{"field":"deal_date","operator":">=","value":"2025-04-01"}]. Операторы: =, !=, >, <, >=, <=, like, in, not in. Для in/not in value должен быть массивом.', false);

        if ($isQuickQa) {
            $tool
                ->withStringParameter(
                    'group_by',
                    'JSON массив имён полей для группировки: ["user_id"] или ["user_id","deal_status"]. '
                        . 'Если задан — ответ будет массивом строк {<field>: value, ..., aggregate: <число>}. '
                        . 'Имена полей — простые идентификаторы (без точек).',
                    false,
                )
                ->withStringParameter(
                    'order_by',
                    'JSON массив сортировки: [{"field":"aggregate","dir":"desc"}]. '
                        . 'field — одно из имён в group_by ИЛИ литерал "aggregate" для сортировки по агрегату. '
                        . 'dir — "asc" или "desc".',
                    false,
                )
                ->withStringParameter(
                    'limit',
                    'Максимум строк в ответе при group_by (по умолчанию 50, потолок 200). Игнорируется без group_by.',
                    false,
                );
        }

        // IMPORTANT: closure parameter names MUST match the schema parameter
        // names declared above (model, aggregate, field, filters, group_by,
        // order_by, limit). Prism dispatches tool calls via
        // `call_user_func_array($tool->handle(...), $toolCall->arguments())`
        // where arguments() is an *associative* array. PHP spreads associative
        // arrays as named arguments, so a mismatch raises
        // `Error: Unknown named parameter $filters` which Prism rewraps as
        // "Parameter validation error: Unknown parameters. Expected: [...]".
        // GLM then retries the same call up to ~20 times, burning >1M tokens
        // per turn. Don't rename these.
        //
        // Also: parameter types are `mixed` (not `?string`) on purpose. The
        // schema declares StringSchema, but some providers (GLM in particular)
        // sometimes ignore the string hint and send already-parsed arrays for
        // JSON-shaped values. We accept both shapes and normalize via
        // `coerceJsonArrayArg()` rather than letting PHP raise a TypeError.
        return $tool->using(function (
            string $model,
            string $aggregate,
            mixed $field = null,
            mixed $filters = null,
            mixed $group_by = null,
            mixed $order_by = null,
            mixed $limit = null,
        ) use ($dataProbeService, $company, $chat, $isQuickQa, $emitter, $self): string {
            // Pre-compute summary metrics for the tool_call event. We do this
            // BEFORE the rate-limit check so the frontend always sees a
            // tool_call event even if we then throw — paired with the failure
            // tool_result that follows.
            $filtersCount = 0;
            if (is_array($filters)) {
                $filtersCount = count($filters);
            } elseif (is_string($filters) && $filters !== '') {
                $decoded = json_decode($filters, true);
                if (is_array($decoded)) {
                    $filtersCount = count($decoded);
                }
            }
            $groupByName = null;
            if (is_array($group_by) && !empty($group_by)) {
                $first = reset($group_by);
                if (is_string($first)) {
                    $groupByName = $first;
                }
            } elseif (is_string($group_by) && $group_by !== '') {
                $decoded = json_decode($group_by, true);
                if (is_array($decoded) && !empty($decoded) && is_string($decoded[0] ?? null)) {
                    $groupByName = $decoded[0];
                }
            }

            $callPayload = [
                'model'         => $model,
                'aggregate'     => $aggregate,
                'filters_count' => $filtersCount,
            ];
            if ($groupByName !== null) {
                $callPayload['group_by'] = $groupByName;
            }
            $self->emitToolCall($emitter, 'query_data', $callPayload);

            try {
                // Rate-limit for quick_qa: 20 calls/min per chat. Counted before
                // any DB work. Bursting beyond the cap returns a structured error
                // that the LLM can surface to the user.
                if ($isQuickQa) {
                    $this->enforceQueryDataRateLimit($chat);
                }

                $filtersArr = $this->coerceJsonArrayArg($filters, 'filters');
                if (!is_array($filtersArr)) {
                    $self->emitToolResult($emitter, 'query_data', false, ['error' => $filtersArr]);
                    return json_encode(['error' => $filtersArr]);
                }
                $groupByArr = $this->coerceJsonArrayArg($group_by, 'group_by');
                if (!is_array($groupByArr)) {
                    $self->emitToolResult($emitter, 'query_data', false, ['error' => $groupByArr]);
                    return json_encode(['error' => $groupByArr]);
                }
                $orderByArr = $this->coerceJsonArrayArg($order_by, 'order_by');
                if (!is_array($orderByArr)) {
                    $self->emitToolResult($emitter, 'query_data', false, ['error' => $orderByArr]);
                    return json_encode(['error' => $orderByArr]);
                }

                $limitInt = $this->coerceLimitArg($limit);

                // `field` may arrive as null, empty string, or — defensively — a
                // non-string scalar. Normalize to ?string for DataProbeService.
                $fieldStr = null;
                if ($field !== null && $field !== '') {
                    if (!is_scalar($field)) {
                        $self->emitToolResult($emitter, 'query_data', false, ['error' => 'field must be a string identifier']);
                        return json_encode(['error' => 'field must be a string identifier']);
                    }
                    $fieldStr = (string) $field;
                }

                $result = $dataProbeService->query(
                    $company,
                    $model,
                    $aggregate,
                    $fieldStr,
                    $filtersArr,
                    $groupByArr,
                    $orderByArr,
                    $limitInt,
                );

                // Pick a short numeric / structural summary for the tool_result.
                // For ungrouped queries that's the scalar aggregate value; for
                // grouped queries it's the row count of the returned slice.
                // Never serialize the full result — that's what the LLM gets
                // via the closure return value, and the frontend doesn't need
                // it on the timeline.
                $resultPayload = [];
                if (!empty($groupByArr) && isset($result['rows']) && is_array($result['rows'])) {
                    $resultPayload['group_rows_count'] = count($result['rows']);
                } elseif (array_key_exists('result', $result)) {
                    $resultPayload['aggregate_value'] = is_scalar($result['result']) ? $result['result'] : null;
                }
                $self->emitToolResult($emitter, 'query_data', true, $resultPayload);

                return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (\Throwable $e) {
                // Log the cause so a post-mortem can see WHY a query failed
                // (MacroData connection / SQL / unknown column) without
                // re-running the turn. InvalidArgumentException here is usually
                // a user-recoverable validation issue; everything else is a
                // genuine failure — log both at error so the worker log is the
                // single place to look.
                Log::error('query_data tool failed', [
                    'company_id' => $company?->id,
                    'model'      => $model,
                    'aggregate'  => $aggregate,
                    'exception'  => get_class($e),
                    'message'    => $e->getMessage(),
                ]);

                $self->emitToolResult($emitter, 'query_data', false, [
                    'error' => \Illuminate\Support\Str::limit($e->getMessage(), 300),
                ]);

                return json_encode(['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Enforce query_data rate-limit for a single chat: max 20 calls in any
     * sliding 60-second window. Counter is stored in chat.ai_context under
     * 'query_data_calls' as an array of unix timestamps. Old entries are
     * pruned on every call.
     *
     * Why not Redis / RateLimiter? Because:
     *   - chat.ai_context already round-trips on every message turn.
     *   - The data is bound to chat lifetime and survives across requests.
     *   - Per-chat cap is what we want (not per-user, not per-IP), which
     *     would be awkward to express through Laravel's RateLimiter.
     *
     * @throws \InvalidArgumentException When the cap is exceeded
     */
    protected function enforceQueryDataRateLimit(Chat $chat): void
    {
        $now = time();
        $windowStart = $now - 60;
        $context = $chat->ai_context ?? [];
        $calls = $context['query_data_calls'] ?? [];

        // Prune timestamps older than 60s
        $calls = array_values(array_filter($calls, fn($ts) => $ts >= $windowStart));

        if (count($calls) >= 20) {
            throw new \InvalidArgumentException(
                'Rate limit exceeded: query_data may be called at most 20 times per minute per chat. '
                . 'Слишком много запросов к данным в этом чате — подожди немного и попробуй сформулировать вопрос точнее, '
                . 'чтобы получить ответ за меньшее количество запросов.'
            );
        }

        $calls[] = $now;
        $context['query_data_calls'] = $calls;
        $chat->update(['ai_context' => $context]);
    }

    /**
     * Coerce a tool argument that should end up as a PHP array, regardless
     * of whether the LLM sent it as a JSON string (the documented contract,
     * since the schema is StringSchema) or as an already-parsed array (some
     * providers, notably GLM, occasionally do this — see the comment in
     * queryDataTool()'s closure for context).
     *
     * Returns:
     *   - array<mixed> on success (including [] for null / empty input)
     *   - string error message on malformed input — caller wraps it in
     *     `{"error": ...}` JSON so the LLM gets a structured failure to read.
     *
     * @return array<int|string, mixed>|string
     */
    protected function coerceJsonArrayArg(mixed $value, string $paramName): array|string
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return "{$paramName} must be a valid JSON array (got malformed JSON string)";
            }
            if (!is_array($decoded)) {
                return "{$paramName} must be a JSON array";
            }

            return $decoded;
        }

        return "{$paramName} must be a JSON array or array value";
    }

    /**
     * Coerce a tool argument that should be an integer row-limit. Accepts:
     *   - null / empty string → null (no limit override)
     *   - int → as-is (positive)
     *   - numeric string → cast to int
     * Any other shape is silently treated as null; downstream caps still
     * apply (DataProbeService enforces an absolute ceiling).
     */
    protected function coerceLimitArg(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Tool: create_report — AI creates a new report.
     *
     * Flow:
     *   1. Decode title / description / config JSON.
     *   2. Normalize via ConfigNormalizer (snake_case → canonical).
     *   3. Pre-validate relation_aggregate columns — relation must be
     *      HasMany/HasOne (BelongsTo and others are rejected without saving).
     *   4. Save the Report.
     *   5. Dry-run: call ReportDataService::getData() with page=1, per_page=1.
     *      On exception, tag Report.metadata.dry_run_failed=true and return
     *      a structured failure so the AI can either retry or stop.
     *   6. On second consecutive failure within the same sendMessage() turn,
     *      append a stop-trying directive to the hint — Prism's next step
     *      reads it and emits a final assistant message instead of looping.
     */
    protected function createReportTool(Chat $chat, object $dryRunState, ?ChatEventEmitter $emitter = null): Tool
    {
        $normalizer = $this->configNormalizer;
        $reportDataService = $this->reportDataService;
        $self = $this;

        return (new Tool)
            ->as('create_report')
            ->for('Создать новый отчёт. Параметры — JSON строки. title: {"ru":"...","en":"..."}. config: полный JSON конфиг отчёта с primary_model, columns и т.д. Отчёт — это сухая таблица без визуализации.')
            ->withStringParameter('title', 'JSON строка: {"ru":"Заголовок","en":"Title"}')
            ->withStringParameter('description', 'JSON строка: {"ru":"Описание","en":"Description"} (можно пропустить)', false)
            ->withStringParameter('config', 'Полный JSON конфиг отчёта: {"primary_model":"...","columns":[...]}. Отчёт — это сухая таблица без визуализации (никаких чартов). columns[] поддерживают опциональный description (jsonb {ru,en} ИЛИ простая строка) — человекочитаемое описание колонки для tooltip-иконки в шапке (используй для финансовых/вычисляемых/status/expression-derived колонок). См. секцию «Columns Format» в системном промпте.')
            ->using(function (string $title, string $config, ?string $description = null) use ($chat, $normalizer, $reportDataService, $dryRunState, $emitter, $self): string {
                // Compute a short summary of the incoming title + config for the
                // tool_call event (title preview, columns count, primary_model)
                // BEFORE we even decode — best-effort, never throws. The full
                // config goes to Prism via the closure return value; the event
                // log keeps only what the timeline UI needs.
                $titlePreview = $self->summariseTitle($title);
                $configSummary = $self->summariseConfigForToolCall($config);
                $self->emitToolCall($emitter, 'create_report', array_merge(
                    ['title' => $titlePreview],
                    $configSummary,
                ));

                // Delegate to runCreateReport() so the success/failure
                // tool_result dispatch lives in one place. The wrapper inspects
                // the returned JSON and emits the matching event.
                $resultJson = $self->runCreateReport($title, $config, $description, $chat, $normalizer, $reportDataService, $dryRunState, $emitter);
                $self->emitToolResultFromJson($emitter, 'create_report', $resultJson);
                return $resultJson;
            });
    }

    /**
     * Internal: original body of create_report's closure. Extracted so the
     * tool wrapper above can run a single emit-on-result step after we have
     * the JSON ready. The behaviour is unchanged — same dry-run, same error
     * shape, same exception handling as before.
     *
     * @internal Public visibility is only required so the tool closure can
     * call into it via $self. Treat as protected.
     */
    public function runCreateReport(
        string $title,
        string $config,
        ?string $description,
        Chat $chat,
        ConfigNormalizer $normalizer,
        ReportDataService $reportDataService,
        object $dryRunState,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $titleArr = json_decode($title, true);
            $descArr = $description ? json_decode($description, true) : null;
            $configArr = json_decode($config, true);

            if (!$titleArr || !$configArr) {
                return json_encode(['error' => 'Invalid JSON in title or config']);
            }

            // Normalize snake_case / casing inconsistencies in model + relation
            // names. Returns ok=false with structured errors if anything is
            // unresolvable; the AI can use those to retry with canonical names.
            $normResult = $normalizer->normalize($configArr);

            if (!$normResult['ok']) {
                return json_encode([
                    'error' => 'Config normalization failed',
                    'errors' => $normResult['errors'],
                    'hint' => 'Use canonical names: primary_model is PascalCase (e.g. EstateDeals), relation segments in dotted paths are camelCase (e.g. estateSells.estateHouses.geo_complex_name).',
                ], JSON_UNESCAPED_UNICODE);
            }

            $configArr = $normResult['config'];

            $primaryModel = $configArr['primary_model'] ?? null;
            if (!$primaryModel) {
                return json_encode(['error' => 'primary_model is required in config']);
            }

            // Fallback safety net — normalizer should have caught unknown
            // models already, but keep this in case the canonical map is
            // stale or the file was just added.
            $modelClass = "App\\Models\\MacroData\\{$primaryModel}";
            if (!class_exists($modelClass)) {
                return json_encode(['error' => "Model not found: {$primaryModel}"]);
            }

            // Pre-validation: relation_aggregate columns must point at
            // HasMany/HasOne — BelongsTo aggregates a single row, which
            // is meaningless (and ReportDataService silently drops it
            // at SELECT time, producing a "broken" report). Reject BEFORE
            // saving so AI gets actionable feedback.
            $preErrors = $this->prevalidateRelationAggregates($configArr, $modelClass);
            if (!empty($preErrors)) {
                return json_encode([
                    'success' => false,
                    'errors' => $preErrors,
                    'hint' => 'relation_aggregate requires a HasMany or HasOne relation on the primary model (each primary row must aggregate many related rows). Restructure the column to use a HasMany/HasOne relation, or pick a different aggregation strategy.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Pre-validation: custom_attribute columns must declare valid
            // attr_source, entity (for estate_attributes), and attr_id or attr_name.
            $customAttrErrors = $this->prevalidateCustomAttributes($configArr);
            if (!empty($customAttrErrors)) {
                return json_encode([
                    'success' => false,
                    'errors' => $customAttrErrors,
                    'hint' => 'custom_attribute columns require: attr_source (estate_attributes|estate_sells_attr), attr_id (int) or attr_name (string), and entity (estate_sell|estate_deal|estate_buy|contacts|promos) when attr_source=estate_attributes.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Pre-validation: columns[].description — tooltip text for
            // the column header. Soft type-check (jsonb {ru,en} object,
            // plain string, or null). See prevalidateColumnDescriptions().
            $descriptionErrors = $this->prevalidateColumnDescriptions($configArr);
            if (!empty($descriptionErrors)) {
                return json_encode([
                    'success' => false,
                    'errors' => $descriptionErrors,
                    'hint' => 'columns[].description is an optional tooltip text rendered as a "?" icon next to the column header. It must be a {ru, en} object, a plain string, or null. Use jsonb for full RU+EN localisation, or a string for RU-only.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (!empty($normResult['changes'])) {
                Log::info('AI config normalized', [
                    'chat_id' => $chat->id,
                    'tool' => 'create_report',
                    'changes' => $normResult['changes'],
                ]);
            }

            $report = Report::create([
                'title' => $titleArr,
                'description' => $descArr,
                'config' => $configArr,
                'is_system' => false,
                'user_id' => $chat->user_id,
                'company_id' => $chat->company_id,
                'is_published' => false,
            ]);

            $chat->update(['report_id' => $report->id]);

            return $this->runDryRunAndBuildResponse(
                $report,
                $chat,
                $reportDataService,
                $dryRunState,
                normalizedChanges: $normResult['changes'],
                tool: 'create_report',
                created: true,
                emitter: $emitter,
            );
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Tool: update_report — AI updates an existing report config.
     *
     * Same dry-run + pre-validation flow as createReport; see that method for
     * the full picture.
     */
    protected function updateReportTool(Chat $chat, object $dryRunState, ?ChatEventEmitter $emitter = null): Tool
    {
        $normalizer = $this->configNormalizer;
        $reportDataService = $this->reportDataService;
        $self = $this;

        return (new Tool)
            ->as('update_report')
            ->for('Обновить конфиг существующего отчёта. Параметр — JSON строка с ПОЛНЫМ обновлённым конфигом. Отчёт — это сухая таблица без визуализации.')
            ->withStringParameter('config', 'Полный обновлённый JSON конфиг: {"primary_model":"...","columns":[...]}. Отчёт — это сухая таблица без визуализации (никаких чартов). columns[] поддерживают опциональный description (jsonb {ru,en} ИЛИ простая строка) — tooltip-описание в шапке колонки. См. секцию «Columns Format» в системном промпте.')
            ->using(function (string $config) use ($chat, $normalizer, $reportDataService, $dryRunState, $emitter, $self): string {
                // Short summary for the tool_call event. The full updated
                // config is still the closure return value Prism reads —
                // event log only holds a few KB of metrics.
                $configSummary = $self->summariseConfigForToolCall($config);
                $callPayload = ['report_id' => $chat->report_id ?? null] + $configSummary;
                $self->emitToolCall($emitter, 'update_report', $callPayload);

                $resultJson = $self->runUpdateReport($config, $chat, $normalizer, $reportDataService, $dryRunState, $emitter);
                $self->emitToolResultFromJson($emitter, 'update_report', $resultJson);
                return $resultJson;
            });
    }

    /**
     * Internal: extracted update_report body. See runCreateReport() for the
     * rationale (single emit-on-result hook for the tool wrapper).
     *
     * @internal Public visibility is only required so the tool closure can
     * call into it via $self. Treat as protected.
     */
    public function runUpdateReport(
        string $config,
        Chat $chat,
        ConfigNormalizer $normalizer,
        ReportDataService $reportDataService,
        object $dryRunState,
        ?ChatEventEmitter $emitter,
    ): string {
        try {
            $report = $chat->report;

            if (!$report) {
                return json_encode(['error' => 'No report linked to this chat. Use create_report first.']);
            }

            $configArr = json_decode($config, true);
            if (!$configArr) {
                return json_encode(['error' => 'Invalid JSON in config']);
            }

            // Same normalisation pipeline as create_report.
            $normResult = $normalizer->normalize($configArr);

            if (!$normResult['ok']) {
                return json_encode([
                    'error' => 'Config normalization failed',
                    'errors' => $normResult['errors'],
                    'hint' => 'Use canonical names: primary_model is PascalCase (e.g. EstateDeals), relation segments in dotted paths are camelCase (e.g. estateSells.estateHouses.geo_complex_name).',
                ], JSON_UNESCAPED_UNICODE);
            }

            $configArr = $normResult['config'];

            $primaryModel = $configArr['primary_model'] ?? null;
            $modelClass = null;
            if ($primaryModel) {
                $modelClass = "App\\Models\\MacroData\\{$primaryModel}";
                if (!class_exists($modelClass)) {
                    return json_encode(['error' => "Model not found: {$primaryModel}"]);
                }
            }

            // Same pre-validation as create_report. Skipped only if the
            // incoming config somehow has no primary_model (caught above
            // for create_report; for update_report we keep the existing
            // primary_model on the report — but the column list comes
            // from the new config, so use that primary model for the
            // check).
            if ($modelClass !== null) {
                $preErrors = $this->prevalidateRelationAggregates($configArr, $modelClass);
                if (!empty($preErrors)) {
                    return json_encode([
                        'success' => false,
                        'errors' => $preErrors,
                        'hint' => 'relation_aggregate requires a HasMany or HasOne relation on the primary model (each primary row must aggregate many related rows). Restructure the column to use a HasMany/HasOne relation, or pick a different aggregation strategy.',
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            // Same custom_attribute check as create_report.
            $customAttrErrors = $this->prevalidateCustomAttributes($configArr);
            if (!empty($customAttrErrors)) {
                return json_encode([
                    'success' => false,
                    'errors' => $customAttrErrors,
                    'hint' => 'custom_attribute columns require: attr_source (estate_attributes|estate_sells_attr), attr_id (int) or attr_name (string), and entity (estate_sell|estate_deal|estate_buy|contacts|promos) when attr_source=estate_attributes.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Same description soft-check as create_report.
            $descriptionErrors = $this->prevalidateColumnDescriptions($configArr);
            if (!empty($descriptionErrors)) {
                return json_encode([
                    'success' => false,
                    'errors' => $descriptionErrors,
                    'hint' => 'columns[].description is an optional tooltip text rendered as a "?" icon next to the column header. It must be a {ru, en} object, a plain string, or null. Use jsonb for full RU+EN localisation, or a string for RU-only.',
                ], JSON_UNESCAPED_UNICODE);
            }

            if (!empty($normResult['changes'])) {
                Log::info('AI config normalized', [
                    'chat_id' => $chat->id,
                    'tool' => 'update_report',
                    'changes' => $normResult['changes'],
                ]);
            }

            $report->update(['config' => $configArr]);

            return $this->runDryRunAndBuildResponse(
                $report,
                $chat,
                $reportDataService,
                $dryRunState,
                normalizedChanges: $normResult['changes'],
                tool: 'update_report',
                created: false,
                emitter: $emitter,
            );
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Pre-validation + dry-run helpers
    // -------------------------------------------------------------------------

    /**
     * Walk the config's `columns` looking for relation_aggregate entries whose
     * first-hop relation is not HasMany / HasOne on the primary model.
     *
     * BelongsTo / BelongsToMany / MorphTo / etc. don't make sense here:
     * relation_aggregate computes COUNT/SUM/... over multiple related rows per
     * primary row. A BelongsTo by definition yields at most one. The downstream
     * ReportDataService silently logs and skips such columns (see the
     * "unsupported relation type" warning in applyRelationAggregateSelects),
     * which leaves the user with a broken report and no signal. We reject at
     * tool-level so AI gets an actionable error during the same chat turn.
     *
     * Note: the first hop is the one constrained. Inside a `through` chain,
     * later hops may include BelongsTo — that's fine and supported by
     * ReportDataService::buildThroughSubquery.
     *
     * @param array<string, mixed> $configArr  AI-supplied config (already normalized)
     * @param string $primaryModelClass        Fully-qualified MacroData model class
     * @return list<array{field: string, type: string, message: string}>
     */
    protected function prevalidateRelationAggregates(array $configArr, string $primaryModelClass): array
    {
        $columns = $configArr['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return [];
        }

        // Instantiate the primary model. Bypass the constructor to avoid any
        // DB/boot side-effects — relation methods only build relation objects,
        // they don't execute queries.
        try {
            $primaryInstance = (new \ReflectionClass($primaryModelClass))
                ->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            // If we can't even instantiate the model, let downstream code blow
            // up with a clearer error — don't synthesize a confusing one here.
            return [];
        }

        if (!$primaryInstance instanceof Model) {
            return [];
        }

        $shortModelName = (new \ReflectionClass($primaryModelClass))->getShortName();
        $errors = [];

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            if (($column['type'] ?? null) !== 'relation_aggregate') {
                continue;
            }

            $field    = (string) ($column['field'] ?? '<unknown>');
            $relation = $column['aggregate']['relation'] ?? null;

            if (!is_string($relation) || $relation === '') {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'invalid_relation',
                    'message' => "Column '{$field}' is relation_aggregate but aggregate.relation is missing or empty.",
                ];
                continue;
            }

            // Validate identifier shape before we let it reach method_exists()
            // — defence-in-depth against reflection on weird strings.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relation)) {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'invalid_relation',
                    'message' => "Column '{$field}': relation name '{$relation}' is not a valid PHP method identifier.",
                ];
                continue;
            }

            if (!method_exists($primaryInstance, $relation)) {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'unknown_relation',
                    'message' => "Column '{$field}': relation '{$relation}' does not exist on {$shortModelName}.",
                ];
                continue;
            }

            try {
                $relationObj = $primaryInstance->{$relation}();
            } catch (\Throwable $e) {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'unknown_relation',
                    'message' => "Column '{$field}': failed to resolve relation '{$relation}' on {$shortModelName} ({$e->getMessage()}).",
                ];
                continue;
            }

            if (!($relationObj instanceof HasMany) && !($relationObj instanceof HasOne)) {
                $actualType = is_object($relationObj)
                    ? (new \ReflectionClass($relationObj))->getShortName()
                    : gettype($relationObj);

                $errors[] = [
                    'field'   => $field,
                    'type'    => 'invalid_relation',
                    'message' => "Relation '{$relation}' on {$shortModelName} is {$actualType}; relation_aggregate requires HasMany or HasOne (each primary row should have many related rows to aggregate).",
                ];
            }
        }

        return $errors;
    }

    /**
     * Pre-validate all custom_attribute columns in the report config.
     *
     * Validates required fields and allowed values for each column of
     * type=custom_attribute before the config is saved and a dry-run is attempted.
     *
     * Rules:
     *   - attr_source must be 'estate_attributes' or 'estate_sells_attr'.
     *   - attr_id (int) or attr_name (string) must be present.
     *   - When attr_source='estate_attributes', entity must be one of the allowed values.
     *   - attr_id must be a positive integer when specified as int.
     *   - attr_name must be a non-empty string when specified.
     *
     * @param array<string, mixed> $configArr
     * @return list<array{field: string, type: string, message: string}>
     */
    protected function prevalidateCustomAttributes(array $configArr): array
    {
        $columns = $configArr['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return [];
        }

        $allowedSources  = ['estate_attributes', 'estate_sells_attr'];
        $allowedEntities = ['estate_sell', 'estate_deal', 'estate_buy', 'contacts', 'promos'];

        $errors = [];

        foreach ($columns as $idx => $column) {
            if (!is_array($column)) {
                continue;
            }
            if (($column['type'] ?? null) !== 'custom_attribute') {
                continue;
            }

            $field       = is_string($column['field'] ?? null) ? $column['field'] : "columns[{$idx}]";
            $columnLabel = "columns[{$idx}] (field='{$field}')";
            $attrSource  = $column['attr_source'] ?? null;

            // attr_source is required and must be whitelisted.
            if (!in_array($attrSource, $allowedSources, true)) {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'invalid_attr_source',
                    'message' => "{$columnLabel}: 'attr_source' must be 'estate_attributes' or 'estate_sells_attr'; got " . json_encode($attrSource) . ".",
                ];
                continue;
            }

            // attr_id or attr_name must be provided.
            $hasAttrId   = array_key_exists('attr_id', $column);
            $hasAttrName = array_key_exists('attr_name', $column);

            if (!$hasAttrId && !$hasAttrName) {
                $errors[] = [
                    'field'   => $field,
                    'type'    => 'missing_attr_identifier',
                    'message' => "{$columnLabel}: at least one of 'attr_id' (int) or 'attr_name' (string) must be specified.",
                ];
                continue;
            }

            if ($hasAttrId) {
                $attrIdVal = $column['attr_id'];
                if (!is_int($attrIdVal) && !ctype_digit((string) $attrIdVal)) {
                    $errors[] = [
                        'field'   => $field,
                        'type'    => 'invalid_attr_id',
                        'message' => "{$columnLabel}: 'attr_id' must be a positive integer; got " . json_encode($attrIdVal) . ".",
                    ];
                    continue;
                }
                if ((int) $attrIdVal <= 0) {
                    $errors[] = [
                        'field'   => $field,
                        'type'    => 'invalid_attr_id',
                        'message' => "{$columnLabel}: 'attr_id' must be a positive integer (> 0); got {$attrIdVal}.",
                    ];
                    continue;
                }
            }

            if ($hasAttrName) {
                $attrNameVal = $column['attr_name'];
                if (!is_string($attrNameVal) || trim($attrNameVal) === '') {
                    $errors[] = [
                        'field'   => $field,
                        'type'    => 'invalid_attr_name',
                        'message' => "{$columnLabel}: 'attr_name' must be a non-empty string; got " . json_encode($attrNameVal) . ".",
                    ];
                    continue;
                }
            }

            // entity is required for estate_attributes source.
            if ($attrSource === 'estate_attributes') {
                $entity = $column['entity'] ?? null;
                if (!in_array($entity, $allowedEntities, true)) {
                    $errors[] = [
                        'field'   => $field,
                        'type'    => 'invalid_entity',
                        'message' => "{$columnLabel}: 'entity' must be one of [" . implode(', ', $allowedEntities) . "] when attr_source='estate_attributes'; got " . json_encode($entity) . ".",
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Pre-validate the optional `columns[].description` key — human-readable
     * tooltip text rendered as a "?" icon next to the column header on the
     * frontend. Backend stores it untouched in jsonb; we only enforce a soft
     * type-check so AI can't slip in malformed values (arrays of mixed shape,
     * numeric scalars, etc.) that would crash the frontend renderer.
     *
     * Accepted shapes:
     *   - `{"ru": "...", "en": "..."}` — full localisation (any subset of keys
     *      is acceptable as long as it's an associative array of strings;
     *      extra locale keys are tolerated).
     *   - plain string — RU-only default, matches the AI-prompt default locale.
     *   - `null` or absent — column has no tooltip.
     *
     * Anything else (numbers, booleans, list arrays, nested objects with
     * non-string leaf values) is reported as `invalid_description`. The hint
     * surfaced to the LLM nudges it back to the documented shape.
     *
     * @param array<string, mixed> $configArr
     * @return list<array{column: string, type: string, message: string}>
     */
    protected function prevalidateColumnDescriptions(array $configArr): array
    {
        $columns = $configArr['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return [];
        }

        $errors = [];

        foreach ($columns as $idx => $column) {
            if (!is_array($column)) {
                continue;
            }
            if (!array_key_exists('description', $column)) {
                continue;
            }

            $field       = is_string($column['field'] ?? null) ? $column['field'] : "columns[{$idx}]";
            $columnLabel = "columns[{$idx}] (field='{$field}')";
            $value       = $column['description'];

            // null + plain string are accepted as-is.
            if ($value === null || is_string($value)) {
                continue;
            }

            // Otherwise it must be an associative {ru,en}-style object with
            // string leaf values. Reject list-arrays and non-string leaves.
            if (!is_array($value) || array_is_list($value)) {
                $errors[] = [
                    'column'  => $columnLabel,
                    'type'    => 'invalid_description',
                    'message' => "{$columnLabel}: 'description' must be a string, a {ru, en} object, or null.",
                ];
                continue;
            }

            foreach ($value as $localeKey => $localeValue) {
                if (!is_string($localeKey) || $localeKey === '') {
                    $errors[] = [
                        'column'  => $columnLabel,
                        'type'    => 'invalid_description',
                        'message' => "{$columnLabel}: 'description' object keys must be non-empty locale strings (e.g. 'ru', 'en').",
                    ];
                    continue 2;
                }
                if (!is_string($localeValue)) {
                    $errors[] = [
                        'column'  => $columnLabel,
                        'type'    => 'invalid_description',
                        'message' => "{$columnLabel}: 'description.{$localeKey}' must be a string.",
                    ];
                    continue 2;
                }
            }
        }

        return $errors;
    }

    /**
     * Run dry-run via ReportDataService::getData() and produce the tool JSON
     * response. Shared between create_report and update_report so the
     * success / failure contract stays identical.
     *
     * On exception:
     *   - Logs a warning with class + message (helps debugging without spamming).
     *   - Tags the Report with metadata.dry_run_failed=true so
     *     ReportController::index filters it out of the user list.
     *   - Increments dryRunState->failures.
     *   - At/above max_semantic_retries, appends a stop-trying directive to the
     *     hint. The AI reads it in the next Prism step and emits a final
     *     assistant text instead of attempting yet another create/update.
     *
     * Why we keep the Report around on failure: it's a debug artefact. AI may
     * also try to update_report it later with a fix — losing it would cost the
     * whole context. The index filter keeps it out of the user's way.
     *
     * @param Report             $report
     * @param Chat               $chat
     * @param ReportDataService  $reportDataService
     * @param object             $dryRunState
     * @param list<array>        $normalizedChanges
     * @param string             $tool      'create_report' | 'update_report'
     * @param bool               $created   true for create, false for update
     * @param ChatEventEmitter|null $emitter Optional event emitter for the
     *                                       async streaming pipeline (M4).
     *                                       When provided, emits
     *                                       dry_run_start / dry_run_result so
     *                                       the frontend can show "checking
     *                                       data..." indicators in real time.
     */
    protected function runDryRunAndBuildResponse(
        Report $report,
        Chat $chat,
        ReportDataService $reportDataService,
        object $dryRunState,
        array $normalizedChanges,
        string $tool,
        bool $created,
        ?ChatEventEmitter $emitter = null,
    ): string {
        $dryRunEnabled = (bool) config('ai.dry_run.enabled', true);

        if (!$dryRunEnabled) {
            return $this->buildSuccessResponse($report, $created, $normalizedChanges, samplePreview: null);
        }

        $company = $chat->company;
        $user    = $chat->user;

        if (!$company || !$user) {
            // Pathological case — chat without company/user can't dry-run.
            // Fall back to "success without preview" so we don't synthesise a
            // spurious failure.
            return $this->buildSuccessResponse($report, $created, $normalizedChanges, samplePreview: null);
        }

        // Frontend "checking data" indicator. Emit after we've decided to
        // actually run a dry-run (not when the feature flag is off) so the
        // frontend doesn't get a misleading event.
        $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_START, [
            'report_id' => $report->id,
            'tool'      => $tool,
        ]);

        $startedAt = microtime(true);

        try {
            $reportData = $reportDataService->getData(
                $report->fresh(), // refresh so getData sees the persisted config
                $company,
                $user,
                ['page' => 1, 'per_page' => 1],
            );

            // getData() returns an array on success. Even when no rows match
            // the filters, that's still a working report. We only fail on
            // thrown exceptions, not on empty data.
            $sample = null;
            if (isset($reportData['data'][0]) && is_array($reportData['data'][0])) {
                $sample = $reportData['data'][0];
            }

            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'report_id' => $report->id,
                'tool'      => $tool,
                'success'   => true,
                'ms'        => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->buildSuccessResponse($report, $created, $normalizedChanges, samplePreview: $sample);
        } catch (\Throwable $e) {
            $emitter?->emit(ChatMessageEvent::TYPE_DRY_RUN_RESULT, [
                'report_id'       => $report->id,
                'tool'            => $tool,
                'success'         => false,
                'ms'              => (int) round((microtime(true) - $startedAt) * 1000),
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            return $this->handleDryRunFailure($report, $chat, $dryRunState, $tool, $e, $emitter);
        }
    }

    /**
     * Build the success-shaped JSON the tool returns when dry-run passes
     * (or is disabled). Kept distinct from the failure path so the contract
     * is easy to read in one place.
     *
     * @param list<array> $normalizedChanges
     * @param array<string, mixed>|null $samplePreview
     */
    protected function buildSuccessResponse(
        Report $report,
        bool $created,
        array $normalizedChanges,
        ?array $samplePreview,
    ): string {
        $response = [
            'success'   => true,
            'report_id' => $report->id,
        ];

        if ($created) {
            $response['url']     = "/api/reports/{$report->id}";
            $response['created'] = true;
        } else {
            $response['updated'] = true;
        }

        if (!empty($normalizedChanges)) {
            $response['normalized_changes'] = $normalizedChanges;
        }

        if ($samplePreview !== null) {
            // Keep only the first row + truncate to avoid token bloat — the AI
            // doesn't need the full dataset, just a signal that data flowed.
            $response['preview'] = ['sample_row' => $samplePreview];
        }

        return json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle the case where getData() threw. Records the failure on the Report,
     * bumps the per-turn counter, and crafts a failure JSON whose `hint` field
     * escalates from "try again" to "stop trying" once max_semantic_retries is
     * exceeded.
     */
    protected function handleDryRunFailure(
        Report $report,
        Chat $chat,
        object $dryRunState,
        string $tool,
        \Throwable $e,
        ?ChatEventEmitter $emitter = null,
    ): string {
        Log::warning('ReportTool dry-run failed', [
            'chat_id'         => $chat->id,
            'report_id'       => $report->id,
            'tool'            => $tool,
            'exception_class' => get_class($e),
            'message'         => $e->getMessage(),
        ]);

        // Tag the report so ReportController::index hides it. We *don't*
        // delete the row: AI may try update_report against it next step, and
        // it's useful debug bread-crumb.
        try {
            $existing = $report->metadata ?? [];
            $report->update([
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
            // Don't let metadata-write failure mask the real error.
            Log::warning('ReportTool: could not tag report.metadata.dry_run_failed', [
                'report_id' => $report->id,
                'error'     => $tagErr->getMessage(),
            ]);
        }

        // Increment per-turn failure counter (shared between create_report and
        // update_report closures via the dryRunState object).
        $dryRunState->failures = ($dryRunState->failures ?? 0) + 1;

        $maxRetries = (int) config('ai.dry_run.max_semantic_retries', 2);
        $exhausted  = $dryRunState->failures >= $maxRetries;

        // Frontend cue: "AI is retrying with a different config". Emitted at
        // the failure point (not at the next tool call) because the LLM may
        // emit a final-message instead of retrying — but the human-readable
        // signal "we just hit a dead end" is still useful.
        $emitter?->emit(ChatMessageEvent::TYPE_RETRY, [
            'attempt'   => $dryRunState->failures,
            'limit'     => $maxRetries,
            'exhausted' => $exhausted,
            'reason'    => 'dry_run_failed',
            'tool'      => $tool,
        ]);

        $hint = $exhausted
            ? "This is dry-run failure #{$dryRunState->failures} in a row "
                . "(limit: {$maxRetries}). STOP trying to create or update the report "
                . 'automatically. In your next reply, do NOT call any tool. Explain to '
                . 'the user that you could not build a working report config and ask '
                . 'them to refine the request (e.g. clarify which fields / filters / '
                . 'aggregations they actually need).'
            : 'Report row was saved (kept as debug artefact, hidden from user list) '
                . 'but data fetch via ReportDataService::getData() failed. Try a simpler '
                . 'configuration: fewer / simpler columns, plain primary_model fields, '
                . 'no relation_aggregate, no group_by. If you cannot find a working '
                . 'config quickly, stop and ask the user.';

        $payload = [
            'success'   => false,
            'report_id' => $report->id,
            'errors'    => [[
                'type'            => 'dry_run_exception',
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]],
            'dry_run_failure_count'    => $dryRunState->failures,
            'dry_run_failure_limit'    => $maxRetries,
            'dry_run_limit_exhausted'  => $exhausted,
            'hint'                     => $hint,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------------------------
    // Tool-event emit helpers (M-AI-stream tool-visibility plan)
    //
    // These helpers wrap ChatEventEmitter so the in-closure call sites stay
    // short and consistent. Each helper:
    //   - is a no-op when the emitter is null (sync sendMessage path).
    //   - swallows emit exceptions and logs a warning. We never want a stream
    //     emit failure to mask the underlying tool's real return value.
    //   - keeps payloads to short summaries — no full args, no full results.
    //     The Prism response still carries the full args/results in
    //     `metadata.tool_calls` / `metadata.tool_results` for analytics; the
    //     event log is strictly for the live frontend timeline UI.
    //
    // Public so the tool closures (which are scoped to $self) can call into
    // them — treat as protected outside of this file.
    // -------------------------------------------------------------------------

    /**
     * Emit a `tool_call` event with a sanitized payload. Called immediately
     * before the tool's actual work begins, so the frontend can show a
     * "querying X..." indicator before the network/DB round-trip starts.
     *
     * @param  array<string, mixed>  $arguments  Short summary — see comment
     *                                           in $emitter docs for shape.
     */
    public function emitToolCall(?ChatEventEmitter $emitter, string $tool, array $arguments): void
    {
        if ($emitter === null) {
            return;
        }

        try {
            $emitter->emit(ChatMessageEvent::TYPE_TOOL_CALL, [
                'tool'      => $tool,
                'arguments' => $arguments,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReportTool: failed to emit tool_call event', [
                'tool'  => $tool,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit a `tool_result` event with a short success/failure summary.
     *
     * @param  array<string, mixed>  $summary  Short summary of result —
     *                                         scalar metrics only.
     */
    public function emitToolResult(?ChatEventEmitter $emitter, string $tool, bool $success, array $summary = []): void
    {
        if ($emitter === null) {
            return;
        }

        try {
            $emitter->emit(ChatMessageEvent::TYPE_TOOL_RESULT, array_merge([
                'tool'    => $tool,
                'success' => $success,
            ], $summary));
        } catch (\Throwable $e) {
            Log::warning('ReportTool: failed to emit tool_result event', [
                'tool'    => $tool,
                'success' => $success,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Inspect a tool's returned JSON string and emit the matching
     * tool_result. Used by create_report / update_report wrappers so the
     * many in-method `return json_encode(...)` exits don't each need their
     * own emit call. We classify success by the shape of the decoded JSON:
     *
     *   - `{"created": true, ...}` or `{"updated": true, ...}` → success
     *   - `{"success": true, ...}` (forward-compat) → success
     *   - everything else → failure with optional `error` / first error type
     *
     * The summary is intentionally tiny: report_id when present, short
     * error message when not. Detailed diagnostics still flow back to the
     * LLM in the full JSON return value, which is what Prism reads.
     */
    public function emitToolResultFromJson(?ChatEventEmitter $emitter, string $tool, string $resultJson): void
    {
        if ($emitter === null) {
            return;
        }

        $decoded = json_decode($resultJson, true);
        if (!is_array($decoded)) {
            // Malformed JSON from the tool body — treat as failure.
            $this->emitToolResult($emitter, $tool, false, [
                'error' => 'tool returned non-JSON response',
            ]);
            return;
        }

        $isSuccess = ($decoded['created'] ?? false) === true
            || ($decoded['updated'] ?? false) === true
            || ($decoded['success'] ?? false) === true;

        if ($isSuccess) {
            $summary = [];
            if (isset($decoded['report_id'])) {
                $summary['report_id'] = (int) $decoded['report_id'];
            }
            $this->emitToolResult($emitter, $tool, true, $summary);
            return;
        }

        // Failure path: pluck the most useful short signal — either `error`
        // (string), the first errors[].type, or a generic "failed".
        $errorHint = null;
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            $errorHint = $decoded['error'];
        } elseif (isset($decoded['errors'][0]['type']) && is_string($decoded['errors'][0]['type'])) {
            $errorHint = $decoded['errors'][0]['type'];
        }

        $summary = [];
        if ($errorHint !== null) {
            $summary['error'] = $errorHint;
        }
        if (isset($decoded['report_id'])) {
            $summary['report_id'] = (int) $decoded['report_id'];
        }
        $this->emitToolResult($emitter, $tool, false, $summary);
    }

    /**
     * Pluck a short title preview from the JSON-stringified title argument.
     * Title is documented as `{"ru":"...","en":"..."}` but AI sometimes
     * sends a bare string — we tolerate both.
     *
     * @internal Public for the tool-closure $self access only.
     */
    public function summariseTitle(string $titleJson): string
    {
        $decoded = json_decode($titleJson, true);
        if (is_array($decoded)) {
            foreach (['ru', 'en'] as $loc) {
                if (isset($decoded[$loc]) && is_string($decoded[$loc]) && $decoded[$loc] !== '') {
                    return mb_substr($decoded[$loc], 0, 120);
                }
            }
        } elseif (is_string($decoded) && $decoded !== '') {
            return mb_substr($decoded, 0, 120);
        }

        // Fall back to the raw input — clamped — so we always have something.
        return mb_substr($titleJson, 0, 120);
    }

    /**
     * Pluck primary_model + columns count from the JSON-stringified config
     * argument. Best-effort — never throws — feeds the tool_call payload
     * before we actually decode the config inside runCreate/UpdateReport().
     *
     * @return array<string, mixed>
     * @internal Public for the tool-closure $self access only.
     */
    public function summariseConfigForToolCall(string $configJson): array
    {
        $decoded = json_decode($configJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        if (isset($decoded['primary_model']) && is_string($decoded['primary_model'])) {
            $out['primary_model'] = $decoded['primary_model'];
        }
        if (isset($decoded['columns']) && is_array($decoded['columns'])) {
            $out['columns_count'] = count($decoded['columns']);
        }

        return $out;
    }
}
