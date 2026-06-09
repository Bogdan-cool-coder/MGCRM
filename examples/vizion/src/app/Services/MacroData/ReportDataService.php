<?php

namespace App\Services\MacroData;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use App\Services\MacroData\ExpressionSqlTranslator;
use App\Services\MacroData\TranslationException;

class ReportDataService
{
    protected ConnectionService $connectionService;
    protected ?ConfigResolver $configResolver = null;
    protected ExpressionLanguage $expressionLanguage;
    protected ExpressionSqlTranslator $sqlTranslator;

    protected array $config;
    protected string $primaryModel;
    protected Model $modelInstance;
    protected array $relations = [];

    /**
     * Current request params (filters / sort / pagination).
     * Stored here so that applyWindowAggregateSelects() can access user-applied
     * filters when building ignore_date_filters correlated subqueries.
     */
    protected array $currentParams = [];

    public function __construct(ConnectionService $connectionService, ConfigResolver $configResolver)
    {
        $this->connectionService = $connectionService;
        $this->configResolver = $configResolver;
        $this->expressionLanguage = new ExpressionLanguage();
        $this->registerExpressionFunctions();
        // PDO unavailable at construction time (MacroData connection not yet established).
        // The translator is re-created with live PDO when needed in translateExpressionToSql().
        $this->sqlTranslator = new ExpressionSqlTranslator(null);
    }

    /**
     * Get full report data for frontend
     */
    public function getData(Report $report, Company $company, User $user, array $params = []): array
    {
        $this->config = $report->config;
        // Inject report ID so buildAvailableFilters can build async search_endpoint URLs.
        $this->config['_report_id'] = $report->id;
        $this->primaryModel = $this->config['primary_model'] ?? 'EstateDeals';

        // Connect to MacroData
        try {
            $this->connectionService->connect($company);
        } catch (\Exception $e) {
            return $this->getEmptyResponse($report);
        }

        // Resolve per-company variable placeholders ({"$company_var": "..."}) in config.
        // Must run after connect() so that Company::macrodataValue() can be called,
        // and before any query-building so resolved values are used throughout.
        $unresolvedVars = [];
        if ($this->configResolver !== null) {
            $this->config = $this->configResolver->resolve($this->config, $company, $unresolvedVars);
        }

        // Get model instance
        $modelClass = $this->getModelClass();
        if (!class_exists($modelClass)) {
            return $this->getEmptyResponse($report);
        }

        $this->modelInstance = new $modelClass;

        // Store current params so that window-aggregate helpers can access applied
        // user filters (needed for ignore_date_filters correlated subquery building).
        $this->currentParams = $params;

        // Extract relations from columns only.
        // group_by is ignored — grouped view has been removed, always flat.
        // Any group_by key present in config (legacy DB records) is silently discarded.
        $this->relations = $this->extractRelations(
            $this->config['columns'] ?? [],
            []
        );

        // Build query
        $query = $this->buildQuery($params);

        // Build filters and totals before pagination (using clone)
        $filtersAvailable = $this->buildAvailableFilters($query->clone());
        $totals = $this->buildTotals($query->clone());

        // Get paginated data — always flat.
        // Grouped view has been removed from the product. group_by in config is ignored.
        // Grouping methods (canUseSqlGroupBy / getGroupedRowsSql / getGroupedRows / getGroupRows)
        // are kept in the file as dormant code but are never called from here.
        $perPage = (int) ($params['per_page'] ?? $this->config['pagination']['default'] ?? 20);

        // Apply window-aggregate SELECT expressions for window_aggregate columns.
        // window_aggregate columns inject SQL window functions into the base query
        // so MySQL returns per-row totals as regular attributes.
        $this->applyWindowAggregateSelects($query);

        // Apply relation-aggregate correlated subquery SELECT expressions.
        // relation_aggregate columns inject correlated COUNT/GROUP_CONCAT subqueries
        // so MySQL returns per-row aggregates as regular attributes.
        $this->applyRelationAggregateSelects($query);

        // Apply custom_attribute correlated subquery SELECT expressions.
        // custom_attribute columns read EAV values from estate_attributes /
        // estate_sells_attr via a correlated subquery keyed on entity + entity_id.
        $this->applyCustomAttributeSelects($query);

        $paginator = $query->paginate($perPage);
        $page = $paginator->currentPage();
        $perPage = $paginator->perPage();

        // Batch-load payment schedules for all deals on this page (one query, no N+1).
        $paymentScheduleMap = $this->buildPaymentScheduleMap($paginator->getCollection());

        $rows = $paginator->getCollection()->map(function ($item, $index) use ($page, $perPage, $paymentScheduleMap) {
            return $this->mapRow($item, $index, $page, $perPage, $paymentScheduleMap);
        })->toArray();
        $metaOverride = [
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];

        // Filter out hidden columns
        $visibleColumns = $this->getVisibleColumns();
        $visibleFields = array_column($visibleColumns, 'field');

        // Collect auxiliary fields required by link-type columns (label_field)
        $auxiliaryFields = $this->extraFieldsForColumns($this->config['columns'] ?? []);

        // Collect auxiliary fields required by badge conditions
        $badgeAuxFields = $this->extraFieldsForBadges($this->config['columns'] ?? []);

        // Allowed keys = visible column fields + auxiliary fields + badge auxiliary fields
        $allowedFields = array_unique(array_merge($visibleFields, $auxiliaryFields, $badgeAuxFields));

        // Filter rows to exclude hidden fields (but keep auxiliary fields).
        $filteredRows = array_map(function ($row) use ($allowedFields) {
            return $this->filterRowFields($row, $allowedFields);
        }, $rows);

        // Filter totals to exclude hidden fields
        $visibleTotals = $this->getVisibleTotals($totals);

        // Build meta — merge pagination/group meta with optional unresolved_vars flag.
        $meta = $metaOverride;
        if (!empty($unresolvedVars)) {
            $meta['unresolved_vars'] = array_values($unresolvedVars);
        }

        // Build response
        return [
            'id'              => $report->id,
            'title'           => json_decode($report->getRawOriginal('title'), true),
            'description'     => json_decode($report->getRawOriginal('description'), true),
            'columns'         => $visibleColumns,
            'rows'            => $filteredRows,
            'meta'            => $meta,
            'filters_available' => $filtersAvailable,
            'filters_applied' => $this->getAppliedFilters($params),
            'totals'          => $visibleTotals,
            // Whitelisted projection of report.config sent to the frontend.
            // Only safe, display-oriented keys are forwarded — never raw config
            // blobs or internal resolver artefacts (_report_id etc.).
            'config'          => $this->buildPublicConfigProjection(),
        ];
    }

    /**
     * Build the whitelisted public `config` projection forwarded to the frontend.
     *
     * Strict whitelist — only display-oriented, safe keys are included.
     * Internal artefacts (_report_id, resolver placeholders, raw aggregates etc.)
     * must never leak to the API response.
     *
     * Current whitelist:
     *   - primary_filter  (string) — field name of the header-level primary filter widget.
     *                                Passed through only when present and a non-empty string.
     */
    protected function buildPublicConfigProjection(): array
    {
        $projection = [];

        // Guard: $this->config may be unset if this method is somehow called
        // before getData() initialises it.  Return empty projection safely.
        if (!isset($this->config)) {
            return $projection;
        }

        // primary_filter — the field name the frontend uses to render an inline
        // filter widget in the report header.  Must be a non-empty string; any
        // other type (null, array, int …) is silently ignored so that a
        // misconfigured report does not break the response shape.
        $primaryFilter = $this->config['primary_filter'] ?? null;
        if (is_string($primaryFilter) && $primaryFilter !== '') {
            $projection['primary_filter'] = $primaryFilter;
        }

        return $projection;
    }

    /**
     * Get columns that are not hidden (visible != false).
     *
     * payment_schedule columns always have sortable and filterable forced to false
     * regardless of what the report config declares — the column type is a composite
     * object and cannot be sorted or filtered by the standard mechanisms.
     */
    protected function getVisibleColumns(): array
    {
        $columns = $this->config['columns'] ?? [];
        $visible = array_values(array_filter($columns, fn($col) => ($col['visible'] ?? true) !== false));

        return array_map(function (array $col): array {
            if (($col['type'] ?? null) === 'payment_schedule') {
                $col['sortable']   = false;
                $col['filterable'] = false;
            }
            // column_group was a deprecated UI hint for two-level header
            // rendering. The feature was dropped; strip the key here so it
            // never leaks into the API payload, even if older configs (or
            // chat-authored ones) still carry it.
            unset($col['column_group']);
            return $col;
        }, $visible);
    }

    /**
     * Get totals filtered to exclude hidden fields.
     *
     * Whitelist = visible column fields UNION expose-alias target keys from
     * payment_schedule columns (e.g. paid_total / due_total).  Without the
     * union, expose-aliases computed by buildTotals() would be silently
     * stripped here even though the caller requested them in config.totals.
     */
    protected function getVisibleTotals(array $totals): array
    {
        $visibleFields  = array_column($this->getVisibleColumns(), 'field');
        $exposeKeys     = array_keys($this->collectExposeFields());
        $allowedFields  = array_unique(array_merge($visibleFields, $exposeKeys));

        return array_intersect_key($totals, array_flip($allowedFields));
    }

    /**
     * Filter a row array to only allowed field keys.
     * Badge annotation keys (_badge_*) are always kept.
     */
    protected function filterRowFields(array $row, array $allowedFields): array
    {
        $result = array_intersect_key($row, array_flip($allowedFields));
        // Keep all _badge_* keys regardless of allowedFields
        foreach ($row as $key => $val) {
            if (str_starts_with($key, '_badge_')) {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /**
     * Build base query with relations and filters
     */
    protected function buildQuery(array $params): Builder
    {
        $query = $this->modelInstance->newQuery();

        // Apply global where conditions from report config
        $this->applyGlobalWheres($query);

        // Eager load relations
        if (!empty($this->relations)) {
            $query->with($this->relations);
        }

        // Apply filters
        $this->applyFilters($query, $params);

        // Apply sort
        $this->applySort($query, $params);

        return $query;
    }

    /**
     * Qualify a bare column name with the primary table name to prevent
     * "Column '...' in where/order clause is ambiguous" MySQL errors when
     * applySort() has added one or more LEFT JOINs to the query.
     *
     * Rules:
     *   - If the field already contains a dot (e.g. "sort_join_foo.bar") it is
     *     returned as-is — it is already fully qualified.
     *   - If the field is null or empty it is returned unchanged (callers guard
     *     against null separately).
     *   - Otherwise: "<primary_table>.<field>".
     *
     * Only use this for direct-table columns (no relation hop). Relation fields
     * are routed through whereHas subqueries, which are scoped to the related
     * table, so they never become ambiguous.
     *
     * @param  string|null $field  Raw column name from report config
     * @return string|null         Qualified column name (or null if input was null)
     */
    protected function qualifyPrimaryColumn(?string $field): ?string
    {
        if ($field === null || $field === '' || str_contains($field, '.')) {
            return $field;
        }
        return $this->modelInstance->getTable() . '.' . $field;
    }

    /**
     * Apply global where conditions from report config
     * Supports: whereNotNull, whereNull, whereIn, whereHas conditions
     *
     * whereHas uses a declarative `conditions` array (never closures/eval):
     *   ['type' => 'whereHas', 'relation' => 'estateDeals', 'conditions' => [
     *       ['column' => 'deal_sum', 'operator' => '>', 'value' => 0],
     *       ['column' => 'estate_sells_id', 'operator' => '=', 'value_ref' => 'estate_sells.id'],
     *   ]]
     *
     * Legacy `closure` field is intentionally ignored (never executed).
     *
     * All direct (no-dot) field references are qualified with the primary table
     * name so that they remain unambiguous when applySort() adds LEFT JOINs.
     */
    protected function applyGlobalWheres(Builder $query): void
    {
        $wheres = $this->config['where'] ?? [];

        foreach ($wheres as $condition) {
            $type  = $condition['type'] ?? 'whereNotNull';
            $field = $condition['field'] ?? null;

            // Qualify bare column references with the primary table to prevent
            // "Column '...' in where clause is ambiguous" when sort JOINs are active.
            // Relation (dot-notation) fields are NOT qualified here — they are handled
            // via whereHas subqueries scoped to the related table.
            $qField = ($field !== null && !str_contains($field, '.'))
                ? $this->qualifyPrimaryColumn($field)
                : $field;

            match ($type) {
                'whereNotNull' => $query->whereNotNull($qField),
                'whereNull'    => $query->whereNull($qField),
                'whereIn'      => isset($qField, $condition['value']) && is_array($condition['value'])
                    ? $query->whereIn($qField, $condition['value'])
                    : null,
                'whereNotIn'   => isset($qField, $condition['value']) && is_array($condition['value'])
                    ? $query->whereNotIn($qField, $condition['value'])
                    : null,
                'where'        => isset($qField)
                    ? $query->where($qField, $condition['operator'] ?? '=', $this->resolveDynamicValue($condition['value'] ?? null))
                    : null,
                'whereHas'     => $this->applyWhereHas($query, $condition),
                default        => null,
            };
        }
    }

    /**
     * Apply a structured whereHas condition (no eval, no closures).
     *
     * Accepted condition shape:
     *   'relation'   => string  (required)
     *   'conditions' => array   (required — structured condition list)
     *   'closure'    => *       (legacy field — silently ignored, never executed)
     */
    protected function applyWhereHas(Builder $query, array $condition): mixed
    {
        if (!isset($condition['relation'])) {
            return null;
        }

        // Legacy `closure` field: log and skip to avoid RCE
        if (isset($condition['closure'])) {
            try {
                Log::warning('Legacy whereHas closure ignored — not executed', [
                    'relation' => $condition['relation'],
                ]);
            } catch (\Throwable) {
                // Log facade unavailable outside Laravel container (e.g. unit tests)
            }
        }

        $structuredConditions = $condition['conditions'] ?? null;

        if (!is_array($structuredConditions)) {
            return null;
        }

        return $query->whereHas($condition['relation'], function (Builder $q) use ($structuredConditions) {
            $this->applyStructuredConditions($q, $structuredConditions);
        });
    }

    /**
     * Allowed operators for structured whereHas conditions.
     */
    protected const ALLOWED_OPERATORS = ['=', '!=', '<>', '>', '<', '>=', '<=', 'like', 'in', 'not in', 'is null', 'is not null'];

    /**
     * Apply a flat list of declarative conditions to a query builder.
     * Supports AND nesting via 'and' key and OR nesting via 'or' key (recursive).
     *
     * Each condition:
     *   ['column' => 'deal_sum', 'operator' => '>', 'value' => 0]
     *   ['column' => 'col', 'operator' => '=', 'value_ref' => 'other_table.col']  → whereColumn
     *   ['column' => 'col', 'operator' => 'in', 'value' => [1, 2, 3]]             → whereIn
     *   ['column' => 'col', 'operator' => 'not in', 'value' => [1, 2, 3]]         → whereNotIn
     *   ['column' => 'col', 'operator' => 'is null']                               → whereNull
     *   ['column' => 'col', 'operator' => 'is not null']                           → whereNotNull
     *   ['or' => [...conditions]]                                                   → orWhere group
     *   ['and' => [...conditions]]                                                  → where group (nested AND)
     */
    protected function applyStructuredConditions(Builder $q, array $conditions): void
    {
        foreach ($conditions as $condition) {
            // OR-group: wrap in a where closure and use orWhere for each sub-condition
            if (isset($condition['or']) && is_array($condition['or'])) {
                $q->where(function (Builder $sub) use ($condition) {
                    $this->applyStructuredConditionsOr($sub, $condition['or']);
                });
                continue;
            }

            // AND-group: wrap in a where closure for explicit grouping
            if (isset($condition['and']) && is_array($condition['and'])) {
                $q->where(function (Builder $sub) use ($condition) {
                    $this->applyStructuredConditions($sub, $condition['and']);
                });
                continue;
            }

            $col = $condition['column'] ?? null;
            $op  = strtolower(trim($condition['operator'] ?? '='));

            if ($col === null) {
                continue;
            }

            if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                try {
                    Log::warning('Unsupported operator in structured whereHas condition — skipped', [
                        'column'   => $col,
                        'operator' => $op,
                    ]);
                } catch (\Throwable) {
                    // Log facade unavailable outside Laravel container (e.g. unit tests)
                }
                continue;
            }

            // Dispatch by operator
            match ($op) {
                'is null'     => $q->whereNull($col),
                'is not null' => $q->whereNotNull($col),
                'in'          => $q->whereIn($col, (array) ($condition['value'] ?? [])),
                'not in'      => $q->whereNotIn($col, (array) ($condition['value'] ?? [])),
                default       => isset($condition['value_ref'])
                    ? $q->whereColumn($col, $op, $condition['value_ref'])
                    : $q->where($col, $op, $condition['value'] ?? null),
            };
        }
    }

    /**
     * Same as applyStructuredConditions but uses orWhere for leaf conditions,
     * so that multiple sub-conditions inside an OR-group are joined with OR.
     */
    protected function applyStructuredConditionsOr(Builder $q, array $conditions): void
    {
        foreach ($conditions as $condition) {
            // Nested OR inside OR — still an OR group
            if (isset($condition['or']) && is_array($condition['or'])) {
                $q->orWhere(function (Builder $sub) use ($condition) {
                    $this->applyStructuredConditionsOr($sub, $condition['or']);
                });
                continue;
            }

            // Nested AND inside OR — AND group joined with orWhere
            if (isset($condition['and']) && is_array($condition['and'])) {
                $q->orWhere(function (Builder $sub) use ($condition) {
                    $this->applyStructuredConditions($sub, $condition['and']);
                });
                continue;
            }

            $col = $condition['column'] ?? null;
            $op  = strtolower(trim($condition['operator'] ?? '='));

            if ($col === null) {
                continue;
            }

            if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                Log::warning('Unsupported operator in structured whereHas OR condition — skipped', [
                    'column'   => $col,
                    'operator' => $op,
                ]);
                continue;
            }

            match ($op) {
                'is null'     => $q->orWhereNull($col),
                'is not null' => $q->orWhereNotNull($col),
                'in'          => $q->orWhereIn($col, (array) ($condition['value'] ?? [])),
                'not in'      => $q->orWhereNotIn($col, (array) ($condition['value'] ?? [])),
                default       => isset($condition['value_ref'])
                    ? $q->orWhereColumn($col, $op, $condition['value_ref'])
                    : $q->orWhere($col, $op, $condition['value'] ?? null),
            };
        }
    }

    /**
     * Extract relation chains from column definitions and optional extra dot-notation fields.
     *
     * @param array $columns     Report columns config array
     * @param array $extraFields Additional dot-notation field paths (e.g. from group_by.fields)
     */
    protected function extractRelations(array $columns, array $extraFields = []): array
    {
        $relations = [];

        $addDotField = function (string $field) use (&$relations): void {
            if (!str_contains($field, '.')) {
                return;
            }
            $parts = explode('.', $field);
            array_pop($parts);
            $relationPath = '';
            foreach ($parts as $part) {
                $relationPath = $relationPath ? "$relationPath.$part" : $part;
                $relations[$relationPath] = true;
            }
        };

        foreach ($columns as $column) {
            $addDotField($column['field'] ?? '');

            // Extra relations for renderers (e.g. tags need deeper eager loading)
            foreach ((array)($column['extra_relations'] ?? []) as $extraRelation) {
                $relationPath = '';
                foreach (explode('.', $extraRelation) as $part) {
                    $relationPath = $relationPath ? "$relationPath.$part" : $part;
                    $relations[$relationPath] = true;
                }
            }

            // concat_relation columns declare a `relation` dot-path that must be eager-loaded.
            // Example: relation='estateTagsRelation.tags' → loads estateTagsRelation + estateTagsRelation.tags
            if (($column['type'] ?? null) === 'concat_relation' && !empty($column['relation'])) {
                $addDotField($column['relation'] . '.___leaf');
            }
        }

        // Additional fields (e.g. from group_by.fields)
        foreach ($extraFields as $field) {
            $addDotField($field);
        }

        return array_keys($relations);
    }

    /**
     * Apply filters to query based on column types
     */
    protected function applyFilters(Builder $query, array $params): void
    {
        $userFilters = $params['filters'] ?? [];
        $columns = $this->config['columns'] ?? [];

        // Build column type map, filter_type override map, and filter_field override map.
        $columnTypes        = [];
        $columnFilterTypes  = [];
        $columnFilterFields = []; // filter_field overrides — used for async_select search/apply
        $columnConfigs      = []; // full column config keyed by field (for relation_aggregate lookup)
        foreach ($columns as $column) {
            $field = $column['field'] ?? null;
            if ($field) {
                $columnTypes[$field]        = $column['type']         ?? 'text';
                $columnFilterTypes[$field]  = $column['filter_type']  ?? null;
                $columnFilterFields[$field] = $column['filter_field'] ?? null;
                $columnConfigs[$field]      = $column;
            }
        }

        foreach ($userFilters as $field => $value) {
            if ($value === null) {
                continue;
            }

            // extra_filters: check if this field is declared in config.extra_filters
            // and dispatch accordingly before checking column types.
            $extraFilterDef = $this->findExtraFilter($field);
            if ($extraFilterDef !== null) {
                $this->applyExtraFilter($query, $extraFilterDef, $value);
                continue;
            }

            // Resolve column type early — needed before the async_select check so that
            // computed-alias column types (custom_attribute, window_aggregate, etc.) can
            // be guarded even when filter_type='async_select' is set on the column.
            $columnType = $columnTypes[$field] ?? 'text';

            // custom_attribute: correlated subquery alias — no real column in the primary
            // table, so WHERE on it would cause "Unknown column" SQL errors.
            // Must be guarded BEFORE the async_select branch because a custom_attribute
            // column may carry filter_type='async_select' (to drive the UI search
            // endpoint) while still being non-filterable via direct WHERE.
            // Filtering on EAV values is a future extension.
            if ($columnType === 'custom_attribute') {
                continue;
            }

            // payment_schedule: composite object, not filterable via standard mechanisms.
            if ($columnType === 'payment_schedule') {
                continue;
            }

            // async_select: value is the raw field value; apply as an exact-match 'select' filter.
            // When filter_field is declared on the column, the WHERE goes against filter_field
            // (not the column's field). This handles ID-columns where the user searched by a
            // readable label field (e.g. field=deal_id, filter_field=agreement_number).
            $filterTypeOverride = $columnFilterTypes[$field] ?? null;
            if ($filterTypeOverride === 'async_select') {
                // Resolve the effective field to filter on: filter_field takes priority.
                $effectiveField = $columnFilterFields[$field] ?? $field;
                if (str_contains($effectiveField, '.')) {
                    $this->applyRelationFilter($query, $effectiveField, $value, 'select');
                } else {
                    $this->applyDirectFilter($query, $effectiveField, $value, 'select', true);
                }
                continue;
            }

            // relation_aggregate: filter via repeated correlated subquery in WHERE clause.
            // The alias produced by applyRelationAggregateSelects() is not accessible in WHERE
            // (MySQL resolves SELECT aliases only in ORDER BY / HAVING, not in WHERE of the same
            // query level). We therefore rebuild the subquery inline and apply number_range
            // comparison: WHERE (<correlated subquery>) >= min AND/OR <= max.
            if ($columnType === 'relation_aggregate') {
                $columnCfg = $columnConfigs[$field] ?? null;
                if ($columnCfg !== null) {
                    $this->applyRelationAggregateFilter($query, $columnCfg, $value);
                }
                continue;
            }

            // Get filter type from column type (reuse already-resolved $columnType below)
            $filterType = match ($columnType) {
                'date', 'datetime' => 'date_range',
                'currency', 'number' => 'number_range',
                'badge', 'status' => 'multiselect',
                default => 'text',
            };

            // Check if filter is on a relation
            if (str_contains($field, '.')) {
                $this->applyRelationFilter($query, $field, $value, $filterType);
            } else {
                // qualify=true: prefix bare column with primary table name so that the
                // WHERE clause is unambiguous when applySort() has added LEFT JOINs.
                $this->applyDirectFilter($query, $field, $value, $filterType, true);
            }
        }
    }

    /**
     * Find an extra_filter definition by key in config.extra_filters[].
     * Returns the filter definition array, or null if not found.
     */
    protected function findExtraFilter(string $key): ?array
    {
        foreach ($this->config['extra_filters'] ?? [] as $def) {
            if (($def['key'] ?? null) === $key) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Apply an extra_filter to the query.
     *
     * Currently supported operations:
     *
     * has_any_pivot — matches rows that have at least one related pivot record
     *   with a foreign key IN the supplied value array.
     *
     *   Config fields:
     *     relation         (string)  — Eloquent relation name on the primary model.
     *                                  e.g. 'estateTagsRelation'
     *     foreign_key_field (string) — Column on the pivot/related table to match against.
     *                                  e.g. 'tags_id'
     *
     *   $value must be a non-empty array of IDs.
     *
     *   SQL equivalent: WHERE EXISTS (
     *       SELECT 1 FROM <related_table>
     *       WHERE <fk> = <primary_table>.<pk>
     *         AND <foreign_key_field> IN (...)
     *   )
     */
    protected function applyExtraFilter(Builder $query, array $def, mixed $value): void
    {
        $operation = $def['operation'] ?? null;

        if ($operation === 'has_any_pivot') {
            $relation        = $def['relation']          ?? null;
            $foreignKeyField = $def['foreign_key_field'] ?? null;

            if (!$relation || !$foreignKeyField) {
                return;
            }

            // Value must be a non-empty array
            if (!is_array($value) || empty($value)) {
                return;
            }

            // Validate foreign_key_field as a safe SQL identifier
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $foreignKeyField)) {
                return;
            }

            $query->whereHas($relation, function (Builder $q) use ($foreignKeyField, $value) {
                $q->whereIn($foreignKeyField, $value);
            });

            return;
        }

        // Unknown operation — silently skip to avoid breaking the query.
    }

    /**
     * Apply a number_range filter for a relation_aggregate column.
     *
     * Because the correlated subquery alias lives only in SELECT and is not
     * available inside the WHERE clause of the same query level, we rebuild the
     * correlated subquery from the column's aggregate config and wrap it in a
     * WHERE comparison:
     *
     *   WHERE (SELECT COUNT(*) FROM `tasks` WHERE ...) >= :min
     *   WHERE (SELECT COUNT(*) FROM `tasks` WHERE ...) <= :max
     *
     * Filter value shape (number_range):
     *   ['from' => 1, 'to' => 5]   — both bounds are optional
     *   ['from' => 1]               — only lower bound
     *   ['to' => 3]                 — only upper bound
     *   numeric scalar              — treated as exact match (= value)
     *
     * Security:
     *   - All subquery identifiers come from report.config (admin-only jsonb) and
     *     are validated by buildCorrelatedSubquery() before use.
     *   - Numeric filter values (min/max) are cast to float and bound as PDO
     *     parameters via whereRaw's binding array — no string interpolation.
     *   - Non-numeric values are silently ignored.
     *
     * @param Builder $query      Base query (mutated in place)
     * @param array   $columnCfg Full column config for this relation_aggregate column
     * @param mixed   $value     Filter value from the frontend (number_range format)
     */
    protected function applyRelationAggregateFilter(Builder $query, array $columnCfg, mixed $value): void
    {
        $alias     = $columnCfg['field']     ?? null;
        $aggConfig = $columnCfg['aggregate'] ?? [];

        if (!$alias || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            return;
        }

        $fn           = strtoupper($aggConfig['function'] ?? 'COUNT');
        $relationName = $aggConfig['relation']  ?? null;

        if (!in_array($fn, self::RELATION_AGG_FUNCTIONS, true)) {
            return;
        }

        if (!$relationName || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
            return;
        }

        if (!isset($this->modelInstance) || !method_exists($this->modelInstance, $relationName)) {
            return;
        }

        try {
            $relationObj  = $this->modelInstance->{$relationName}();
            $relatedModel = $relationObj->getRelated();
            $relatedTable = $relatedModel->getTable();
        } catch (\Throwable) {
            return;
        }

        // Dispatch to through-subquery or single-hop depending on config.
        $throughChain = $aggConfig['through'] ?? [];
        if (!empty($throughChain)) {
            $subquerySql = $this->buildThroughSubquery(
                fn           : $fn,
                firstRelObj  : $relationObj,
                firstRelTable: $relatedTable,
                firstRelModel: $relatedModel,
                throughChain : $throughChain,
                aggConfig    : $aggConfig,
                alias        : $alias
            );
        } else {
            [$fkColumn, $pkColumn, $primaryTable] = $this->resolveRelationKeys($relationObj);
            if ($fkColumn === null) {
                return;
            }

            $subquerySql = $this->buildCorrelatedSubquery(
                fn           : $fn,
                relatedTable : $relatedTable,
                fkColumn     : $fkColumn,
                pkColumn     : $pkColumn,
                primaryTable : $primaryTable,
                aggConfig    : $aggConfig,
                alias        : $alias
            );
        }

        if ($subquerySql === null) {
            return;
        }

        // number_range format: ['from' => scalar, 'to' => scalar]
        // Scalar format: treat as exact match.
        if (is_array($value)) {
            $from = isset($value['from']) && is_numeric($value['from']) ? (float) $value['from'] : null;
            $to   = isset($value['to'])   && is_numeric($value['to'])   ? (float) $value['to']   : null;

            if ($from !== null) {
                $query->whereRaw("({$subquerySql}) >= ?", [$from]);
            }
            if ($to !== null) {
                $query->whereRaw("({$subquerySql}) <= ?", [$to]);
            }
        } elseif (is_numeric($value)) {
            $query->whereRaw("({$subquerySql}) = ?", [(float) $value]);
        }
        // Non-numeric / empty — silently skip.
    }

    /**
     * Apply filter on direct field.
     *
     * When called from applyFilters() the $field is a bare column name (no dot).
     * When called from applyRelationFilter() (via whereHas closure) the $field is
     * already the leaf column inside the related table's scope — no primary-table
     * prefix needed there.
     *
     * The $qualify flag controls whether to prefix with the primary table name.
     * It defaults to false so that the whereHas path (relation filters) is
     * unaffected. applyFilters() passes true for direct-field filters so that
     * bare columns are unambiguous when sort JOINs are active.
     */
    protected function applyDirectFilter(Builder $query, string $field, mixed $value, string $type, bool $qualify = false): void
    {
        // Qualify bare column with primary table name when requested (direct-field
        // filters only). This prevents MySQL "Column '...' ambiguous" errors when
        // applySort() has added LEFT JOINs that bring related tables into scope.
        $col = ($qualify && !str_contains($field, '.'))
            ? $this->qualifyPrimaryColumn($field)
            : $field;

        switch ($type) {
            case 'date_range':
                if (isset($value['from'])) {
                    $query->whereDate($col, '>=', $this->parseDateValue($value['from']));
                }
                if (isset($value['to'])) {
                    $query->whereDate($col, '<=', $this->parseDateValue($value['to']));
                }
                break;

            case 'date':
                $query->whereDate($col, $this->parseDateValue($value));
                break;

            case 'multiselect':
                if (is_array($value) && !empty($value)) {
                    $query->whereIn($col, $value);
                }
                break;

            case 'select':
                $query->where($col, $value);
                break;

            case 'number_range':
                if (isset($value['from'])) {
                    $query->where($col, '>=', $value['from']);
                }
                if (isset($value['to'])) {
                    $query->where($col, '<=', $value['to']);
                }
                break;

            case 'number':
                $query->where($col, $value);
                break;

            case 'text':
            default:
                $query->where($col, 'LIKE', "%{$value}%");
                break;
        }
    }

    /**
     * Apply filter on relation field using whereHas
     */
    protected function applyRelationFilter(Builder $query, string $field, mixed $value, string $type): void
    {
        $parts = explode('.', $field);
        $column = array_pop($parts);
        $relation = implode('.', $parts);

        $query->whereHas($relation, function ($q) use ($column, $value, $type) {
            $this->applyDirectFilter($q, $column, $value, $type);
        });
    }

    /**
     * Parse relative date values like '-30 days', 'today'
     */
    protected function parseDateValue(mixed $value): string
    {
        if (is_string($value)) {
            if ($value === 'today') {
                return now()->toDateString();
            }
            if (str_starts_with($value, '-')) {
                // Relative date like '-30 days'
                return now()->modify($value)->toDateString();
            }
            if (str_starts_with($value, '+')) {
                return now()->modify($value)->toDateString();
            }
        }

        return (string)$value;
    }

    /**
     * Resolve dynamic date placeholders in where values.
     *
     * Supported: {today}, {now}, {start_of_month}, {end_of_month},
     *            {start_of_day}, {end_of_day}, {minus_30_days},
     *            {start_of_prev_month}, {minus_2_months}
     *
     * Non-placeholder values are returned as-is.
     */
    protected function resolveDynamicValue(mixed $value): mixed
    {
        if (!is_string($value) || !preg_match('/^\{([^}]+)\}$/', $value, $m)) {
            return $value;
        }

        return match ($m[1]) {
            'today'               => Carbon::today(),
            'now'                 => Carbon::now(),
            'start_of_month'      => Carbon::now()->startOfMonth(),
            'end_of_month'        => Carbon::now()->endOfMonth(),
            'start_of_year'       => Carbon::now()->startOfYear(),
            'end_of_year'         => Carbon::now()->endOfYear(),
            'start_of_day'        => Carbon::today()->startOfDay(),
            'end_of_day'          => Carbon::today()->endOfDay(),
            'minus_30_days'       => Carbon::now()->subDays(30),
            // First day of the previous calendar month (no overflow safe)
            'start_of_prev_month' => Carbon::now()->subMonthNoOverflow()->startOfMonth(),
            // First day two calendar months back (prev month - 1, i.e. month before previous)
            'minus_2_months'      => Carbon::now()->subMonthsNoOverflow(2)->startOfMonth(),
            default               => $value,
        };
    }

    /**
     * Apply sorting to query.
     *
     * Supports four cases:
     *
     *   1. Direct field (no dot)        — plain orderBy, no JOIN needed.
     *   2. Window-aggregate alias       — silently skipped; alias is a computed SELECT
     *                                     expression, not a real column, so ORDER BY on it
     *                                     would produce a MySQL error or wrong results.
     *   3. Link-column with dot-path label_field (1 hop)
     *                                   — frontend sends the column's own `field` value for
     *                                     sort; we locate the column config, detect a dot-path
     *                                     in `label_field`, build a single LEFT JOIN, and
     *                                     ORDER BY <joined_table>.<leaf_column>.
     *   4. Dot-path field (1–2 hops)    — field itself is a dot-path (e.g.
     *                                     `estateSells.geo_flatnum` or
     *                                     `estateSells.estateHouses.name`).
     *                                     For each hop we resolve the BelongsTo / HasOne
     *                                     relation on the current model, build a LEFT JOIN
     *                                     with an alias `sort_join_<relation>`, and finally
     *                                     ORDER BY <last_alias>.<leaf_column>.
     *
     * Chains longer than 2 hops are supported by the same iterative code; they are
     * unusual in MacroData practice but correct as long as all intermediate relations
     * are BelongsTo / HasOne.
     *
     * HasMany / BelongsToMany hops are silently skipped (one-to-many joins would
     * duplicate rows and corrupt pagination counts).
     *
     * All relation names are validated against the live model instance to prevent
     * injection from malformed report config.  The final field name is validated
     * as a safe SQL identifier [a-zA-Z_][a-zA-Z0-9_]* before use in orderBy.
     *
     * group_by mode: applySort() is called from buildQuery(), which is shared by both
     * plain and group-by paths.  In getGroupedRows() / getGroupedRowsSql() the query is
     * rebuilt with reorder() before paging, so any ORDER BY set here is overwritten
     * anyway.  No special guard is needed.
     */
    protected function applySort(Builder $query, array $params): void
    {
        $sortConfig = $this->config['sort'] ?? [];
        $field      = $params['sort']['field'] ?? $sortConfig['default']['field'] ?? null;
        $rawDir     = $params['sort']['direction'] ?? $sortConfig['default']['direction'] ?? 'desc';
        $direction  = in_array(strtolower((string) $rawDir), ['asc', 'desc'], true)
            ? strtolower((string) $rawDir)
            : 'desc';

        if (!$field) {
            return;
        }

        // --- Case 2: window_aggregate alias — skip silently ---
        // Window columns are SELECT aliases (SQL window expression), not real table columns.
        // Sorting by alias would require ORDER BY on a computed expression which is fragile
        // and not needed (window columns are always flagged sortable: false in the guide).
        $windowAliases = array_column(
            array_filter($this->config['columns'] ?? [], fn($c) => ($c['type'] ?? null) === 'window_aggregate'),
            'field'
        );
        if (in_array($field, $windowAliases, true)) {
            return;
        }

        // --- Case 2b: concat_relation alias — skip silently ---
        // concat_relation values are PHP-resolved from eager-loaded collections.
        // There is no SQL column to sort by (no real table column, no window alias).
        // Columns of this type should be declared sortable: false in the report config.
        $concatRelationAliases = array_column(
            array_filter($this->config['columns'] ?? [], fn($c) => ($c['type'] ?? null) === 'concat_relation'),
            'field'
        );
        if (in_array($field, $concatRelationAliases, true)) {
            return;
        }

        // --- Case 2c: payment_schedule alias — skip silently ---
        // payment_schedule values are PHP objects assembled from a batched query.
        // There is no SQL column to sort by.
        $paymentScheduleAliases = array_column(
            array_filter($this->config['columns'] ?? [], fn($c) => ($c['type'] ?? null) === 'payment_schedule'),
            'field'
        );
        if (in_array($field, $paymentScheduleAliases, true)) {
            return;
        }

        // --- Case 2d: relation_aggregate alias — ORDER BY alias (already in SELECT) ---
        // relation_aggregate columns inject a correlated subquery as a SELECT alias
        // via applyRelationAggregateSelects(). MySQL 5.7+/8.0 resolves ORDER BY
        // against SELECT aliases, so `ORDER BY <alias> ASC/DESC` works correctly
        // as long as the alias was added before the ORDER BY is evaluated.
        //
        // Security: alias is validated as a safe SQL identifier before use.
        $relationAggAliases = array_column(
            array_filter($this->config['columns'] ?? [], fn($c) => ($c['type'] ?? null) === 'relation_aggregate'),
            'field'
        );
        if (in_array($field, $relationAggAliases, true)) {
            // Validate the alias as a safe SQL identifier before injecting into ORDER BY.
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                $query->orderBy($field, $direction);
            }
            return;
        }

        // --- Case 2e: custom_attribute alias — ORDER BY alias (already in SELECT) ---
        // custom_attribute columns inject correlated subqueries via applyCustomAttributeSelects().
        // Same pattern as relation_aggregate: ORDER BY the SELECT alias.
        $customAttrAliases = array_column(
            array_filter($this->config['columns'] ?? [], fn($c) => ($c['type'] ?? null) === 'custom_attribute'),
            'field'
        );
        if (in_array($field, $customAttrAliases, true)) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                $query->orderBy($field, $direction);
            }
            return;
        }

        // --- Case 3: link-column sort — checked BEFORE Case 1 ---
        // When the frontend sends the column's own `field` value (e.g. `deal_id`) for sort
        // and that column is type=link, we redirect sorting to the label_field instead of
        // the FK/ID field (which is meaningless for display ordering).
        //
        // The field itself may or may not contain a dot; this check must run before
        // the generic direct-field branch so that link columns are always intercepted.
        $columns = $this->config['columns'] ?? [];
        foreach ($columns as $col) {
            if (($col['field'] ?? null) === $field && ($col['type'] ?? null) === 'link') {
                $labelField = $col['label_field'] ?? null;

                if (!$labelField) {
                    // No label_field defined — fall through to Case 1 so the FK/ID
                    // field itself is used for sorting (e.g. sort by estate_buy_id desc).
                    // Without this, ORDER BY would be absent entirely, causing MySQL to
                    // return rows in storage order and making pagination unstable.
                    break;
                }

                if (str_contains($labelField, '.')) {
                    // Dot-path label_field — sort via JOIN on the related column.
                    $this->applySortViaJoin($query, $labelField, $direction);
                    return;
                }

                // Direct label_field (no dot) — qualify with primary table to avoid
                // ambiguity in case a sort JOIN brought a same-named column in scope.
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $labelField)) {
                    $query->orderBy($this->qualifyPrimaryColumn($labelField), $direction);
                }
                return;
            }
        }

        // --- Case 1: direct field (no dot) ---
        // Qualify with the primary table name so that the ORDER BY is unambiguous
        // even when a dot-path sort on a *different* column has already added a JOIN.
        if (!str_contains($field, '.')) {
            // Validate as safe identifier before passing to orderBy
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                return; // silently skip unsafe field names
            }
            $query->orderBy($this->qualifyPrimaryColumn($field), $direction);
            return;
        }

        // --- Case 4: dot-path in the field itself ---
        $this->applySortViaJoin($query, $field, $direction);
    }

    /**
     * Build LEFT JOINs along a dot-path relation chain and add ORDER BY on the leaf column.
     *
     * Algorithm:
     *   For each hop in the relation chain (all segments except the last):
     *     1. Resolve the Eloquent relation method on the current model class.
     *     2. Accept only BelongsTo / HasOne — skip if the relation is HasMany / BelongsToMany
     *        (many-side joins would cause row duplication and corrupt paginator counts).
     *     3. Build a LEFT JOIN with alias `sort_join_<relation_name>`.
     *        – BelongsTo: JOIN <related_table> AS <alias> ON <current_table>.<fk> = <alias>.<owner_key>
     *        – HasOne:    JOIN <related_table> AS <alias> ON <current_table>.<pk>  = <alias>.<fk>
     *     4. Advance current table alias and model class to the related side.
     *   Then add orderBy('<last_alias>.<leaf_field>', $direction).
     *
     * Security:
     *   - Relation names must exist as methods on the current model (validated via method_exists).
     *   - Leaf field name validated as /^[a-zA-Z_][a-zA-Z0-9_]*$/.
     *   - Table names and key names come from Eloquent internals (not from user input).
     *   - JOIN aliases are derived from validated relation names — no raw user input injected.
     *
     * @param Builder $query
     * @param string  $dotPath   e.g. "estateSells.geo_flatnum" or "estateSells.estateHouses.name"
     * @param string  $direction "asc"|"desc"
     */
    protected function applySortViaJoin(Builder $query, string $dotPath, string $direction): void
    {
        $parts    = explode('.', $dotPath);
        $leafField = array_pop($parts); // last segment = column name

        // Validate leaf field name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $leafField)) {
            return; // silently skip
        }

        // Start from the primary model instance already set on this service.
        // $modelInstance is set by getData() / getGroupRows() before buildQuery() is called.
        // If it is somehow unset (e.g. direct unit-test call), guard with isset.
        if (!isset($this->modelInstance)) {
            return;
        }

        /** @var Model $currentModel */
        $currentModel      = $this->modelInstance;
        $currentTableAlias = $currentModel->getTable(); // initial: real table name (no alias)

        foreach ($parts as $relationName) {
            // Validate relation name as a safe identifier (no spaces, no special chars)
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
                return; // unsafe — skip
            }

            // Validate the relation actually exists on the current model class
            if (!method_exists($currentModel, $relationName)) {
                return; // relation not found — silently skip sort
            }

            $relationObj   = $currentModel->{$relationName}();
            $relatedModel  = $relationObj->getRelated();
            $relatedTable  = $relatedModel->getTable();

            // Derive a unique JOIN alias: sort_join_<relation_name>
            // Using just the leaf relation name is enough for up to 2 hops in practice.
            // For deeper chains use the full path to avoid collisions.
            $joinAlias = 'sort_join_' . $relationName;

            if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                // BelongsTo: FK lives on current table, PK on related table
                // ON <current_alias>.<foreign_key> = <join_alias>.<owner_key>
                $fk       = $relationObj->getForeignKeyName();  // column on current table
                $ownerKey = $relationObj->getOwnerKeyName();    // column on related table

                $query->leftJoin(
                    "{$relatedTable} AS {$joinAlias}",
                    "{$currentTableAlias}.{$fk}",
                    '=',
                    "{$joinAlias}.{$ownerKey}"
                );
            } elseif ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
                // HasOne: FK lives on related table, PK on current model
                // ON <current_alias>.<local_key> = <join_alias>.<foreign_key>
                $localKey = $relationObj->getLocalKeyName(); // column on current table
                $fk       = $relationObj->getForeignKeyName(); // column on related table

                $query->leftJoin(
                    "{$relatedTable} AS {$joinAlias}",
                    "{$currentTableAlias}.{$localKey}",
                    '=',
                    "{$joinAlias}.{$fk}"
                );
            } else {
                // HasMany / BelongsToMany / MorphTo etc. — would produce duplicate rows.
                // Silently skip sort rather than corrupt pagination.
                return;
            }

            // Advance to the related side for the next hop
            $currentModel      = $relatedModel;
            $currentTableAlias = $joinAlias;
        }

        // ORDER BY <last_alias>.<leaf_field>
        $query->orderBy("{$currentTableAlias}.{$leafField}", $direction);
    }

    /**
     * Return auxiliary fields that must be included in the row alongside their column's field.
     * Covers two link-type mechanisms:
     *   - label_field  (single field, legacy)
     *   - label_lines  (array of {prefix, field, default} — multi-line label)
     */
    protected function extraFieldsForColumns(array $columns): array
    {
        $extra = [];
        foreach ($columns as $column) {
            if (($column['type'] ?? null) !== 'link') {
                continue;
            }
            // Legacy single-field label
            if (!empty($column['label_field'])) {
                $extra[] = $column['label_field'];
            }
            // Multi-line label: collect every line's field
            foreach ($column['label_lines'] ?? [] as $line) {
                if (!empty($line['field'])) {
                    $extra[] = $line['field'];
                }
            }
        }
        return array_unique($extra);
    }

    /**
     * Return auxiliary fields required by badge conditions (date_field, status_field).
     * These must survive the allowed-fields filter so that computeBadge() has them.
     */
    protected function extraFieldsForBadges(array $columns): array
    {
        $extra = [];
        foreach ($columns as $column) {
            if (!isset($column['badge']['condition'])) {
                continue;
            }
            $cond = $column['badge']['condition'];
            if (!empty($cond['date_field'])) {
                $extra[] = $cond['date_field'];
            }
            if (!empty($cond['status_field'])) {
                $extra[] = $cond['status_field'];
            }
        }
        return array_unique($extra);
    }

    /**
     * Return all value fields referenced in group_by.aggregates[*].field and
     * group_by.aggregates[*].where.expr (identifier extraction via simple regex).
     *
     * These fields must be present in every mapped row so that:
     *   - computeGroupAggregates() can read the aggregate value field
     *   - filterChildrenForAggregate() can evaluate expression where clauses
     *
     * Example: where.expr = "deal_id != null" → extracts "deal_id"
     */
    protected function extraFieldsForAggregates(): array
    {
        $extra = [];
        foreach ($this->config['group_by']['aggregates'] ?? [] as $aggConfig) {
            // Aggregate value field
            if (!empty($aggConfig['field'])) {
                $extra[] = $aggConfig['field'];
            }
            // Fields referenced in where.expr (expression-type where filter)
            $where = $aggConfig['where'] ?? null;
            if ($where && ($where['type'] ?? null) === 'expression' && !empty($where['expr'])) {
                // Extract simple identifiers (word chars, not starting with digit, no dots)
                // e.g. "deal_id != null" → ["deal_id"]
                preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $where['expr'], $matches);
                $reserved = ['null', 'true', 'false', 'and', 'or', 'not', 'in', 'matches'];
                foreach ($matches[1] as $identifier) {
                    if (!in_array(strtolower($identifier), $reserved, true)) {
                        $extra[] = $identifier;
                    }
                }
            }
        }
        return array_unique($extra);
    }

    /**
     * Return all fields referenced in badge conditions AND group_by aggregate where specs
     * that may not appear as explicit columns. These fields are injected into every mapped
     * row so that filterChildrenForAggregate() can evaluate overdue conditions correctly
     * without access to the underlying Eloquent model.
     */
    protected function extraFieldsForConditions(): array
    {
        $extra = [];

        // Fields from badge conditions
        foreach ($this->config['columns'] ?? [] as $column) {
            $cond = $column['badge']['condition'] ?? null;
            if (!$cond) {
                continue;
            }
            if (!empty($cond['date_field'])) {
                $extra[] = $cond['date_field'];
            }
            if (!empty($cond['status_field'])) {
                $extra[] = $cond['status_field'];
            }
        }

        // Fields from group_by aggregate where specs
        foreach ($this->config['group_by']['aggregates'] ?? [] as $aggConfig) {
            $where = $aggConfig['where'] ?? null;
            if (!$where) {
                continue;
            }
            if (!empty($where['date_field'])) {
                $extra[] = $where['date_field'];
            }
            if (!empty($where['status_field'])) {
                $extra[] = $where['status_field'];
            }
        }

        return array_unique($extra);
    }

    /**
     * Evaluate a badge condition and return badge payload or null.
     *
     * Condition types:
     *   'overdue' — date_field < today AND status_field IN unpaid_status
     *
     * Extendable: add more types in the match below.
     *
     * @param array  $badgeConfig  Column's 'badge' config array
     * @param Model  $item         Eloquent model instance
     * @param array  $row          Already-mapped row values
     * @return array{severity:string,label:mixed}|null
     */
    protected function computeBadge(array $badgeConfig, Model $item, array $row): ?array
    {
        $condition = $badgeConfig['condition'] ?? null;
        if (!$condition) {
            return null;
        }

        $type = $condition['type'] ?? null;

        $matched = match ($type) {
            'overdue' => $this->evaluateOverdueCondition($condition, $item, $row),
            default   => false,
        };

        if (!$matched) {
            return null;
        }

        $severity = $badgeConfig['severity'] ?? 'danger';
        $labelTemplate = $badgeConfig['label'] ?? [];

        // Substitute {days} in label
        $days = $this->getOverdueDays($condition, $item, $row);
        $label = [];
        foreach ((array) $labelTemplate as $lang => $text) {
            $label[$lang] = str_replace('{days}', (string) $days, $text);
        }

        return ['severity' => $severity, 'label' => $label];
    }

    /**
     * Evaluate the 'overdue' badge condition:
     *   date_field < today AND status_field IN unpaid_status
     */
    protected function evaluateOverdueCondition(array $condition, Model $item, array $row): bool
    {
        $dateField   = $condition['date_field']    ?? null;
        $statusField = $condition['status_field']  ?? 'status';
        $unpaidStatuses = $condition['unpaid_status'] ?? [3];

        // Resolve date value from row or direct model attribute
        $dateValue = $row[$dateField] ?? ($dateField ? $this->getFieldValue($item, $dateField) : null);
        if ($dateValue === null) {
            return false;
        }

        try {
            $date = $dateValue instanceof Carbon ? $dateValue : Carbon::parse($dateValue);
        } catch (\Throwable) {
            return false;
        }

        if (!$date->lt(Carbon::today())) {
            return false;
        }

        // Check status — resolve from row or model
        $statusValue = $row[$statusField] ?? $this->getFieldValue($item, $statusField);
        if (!in_array($statusValue, (array) $unpaidStatuses, false)) {
            return false;
        }

        return true;
    }

    /**
     * Calculate days overdue for label substitution.
     */
    protected function getOverdueDays(array $condition, Model $item, array $row): int
    {
        $dateField = $condition['date_field'] ?? null;
        $dateValue = $row[$dateField] ?? ($dateField ? $this->getFieldValue($item, $dateField) : null);

        if ($dateValue === null) {
            return 0;
        }

        try {
            $date = $dateValue instanceof Carbon ? $dateValue : Carbon::parse($dateValue);
            return (int) $date->diffInDays(Carbon::today(), false);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Map model to row array based on columns config
     */
    protected function mapRow(Model $item, int $index = 0, int $page = 1, int $perPage = 20, array $paymentScheduleMap = []): array
    {
        $row = [];
        $columns = $this->config['columns'] ?? [];

        // First pass: collect all raw values
        foreach ($columns as $column) {
            $field = $column['field'];

            // payment_schedule columns: multi-cell showing a deal's payment plan.
            // The schedule is pre-computed in $paymentScheduleMap (keyed by deal PK value)
            // to avoid N+1. sortable and filterable are both forced false.
            if (($column['type'] ?? null) === 'payment_schedule') {
                $pkValue  = $item->getKey();
                $schedule = $paymentScheduleMap[$pkValue] ?? null;
                $row[$field] = $schedule;

                // Optional expose: copy paid_total / due_total into top-level row keys
                // so that plain currency-type columns can reference them by field name.
                // Config example:
                //   'expose' => ['paid_total' => 'paid_total', 'due_total' => 'due_total']
                // The key is the source key inside the schedule object; the value is the
                // top-level row key to write. If the schedule is null the keys are set to null.
                $expose = $column['payments']['expose'] ?? [];
                foreach ($expose as $sourceKey => $targetKey) {
                    if (is_string($targetKey) && $targetKey !== '') {
                        $row[$targetKey] = $schedule[$sourceKey] ?? null;
                    }
                }

                continue;
            }

            // concat_relation columns: aggregate a hasMany/m2m relation field into one string.
            // The `relation` config key is a dot-path to the related model (e.g. 'estateTagsRelation.tags').
            // The `field` config key names the leaf attribute on the final related model.
            // Values are joined with `separator` (default ', ').
            if (($column['type'] ?? null) === 'concat_relation') {
                $row[$field] = $this->resolveConcatRelation($item, $column);
                continue;
            }

            // Guard: if a prior special-type column (e.g. payment_schedule + expose)
            // already wrote this field, do not overwrite it with a raw model read that
            // would return null (the field is computed, not a real DB column).
            //
            // NOTE: the guard only skips the primary field assignment. Link label fields
            // (label_field / label_lines) must still be collected even when the primary
            // field was already written by an earlier column with the same `field` value.
            // Example: report config may declare two link columns sharing field=
            // 'estateSells.estate_sell_id' but with different label_field values
            // (e.g. 'agreement_number' and 'estateSells.geo_flatnum'). Without this split
            // the second column's label_field would be silently dropped.
            if (!array_key_exists($field, $row)) {
                $value = $this->getFieldValue($item, $field);

                // options map is intentionally NOT applied here — raw value is kept.
                // The frontend receives the full options map in columns metadata and
                // localises the display value itself based on the current UI locale.

                // Numeric cast for custom_attribute columns with value_type=number|currency.
                // EAV attr_value is stored as VARCHAR ('46.10', '83.8000', …).
                // Without explicit casting the value is a PHP string, which is passed
                // through evaluateExpression as-is.  Symfony ExpressionLanguage does
                // NOT coerce strings to numbers — so arithmetic with a string operand
                // silently fails (exception caught → 0).  Casting here means the row
                // always carries a float for numeric EAV columns, and ExpressionLanguage
                // addition works correctly.
                //
                // Accepted value_type values: 'number', 'currency' (case-insensitive).
                // Empty / null / non-numeric attr_value → cast to 0.0 (safe sentinel).
                if (($column['type'] ?? null) === 'custom_attribute') {
                    $vt = strtolower((string)($column['value_type'] ?? ''));
                    if (in_array($vt, ['number', 'currency'], true)) {
                        $value = is_numeric($value) ? (float) $value : 0.0;
                    }
                }

                $row[$field] = $value;
            }

            // For link-type columns: include label_field and/or label_lines fields in the row.
            // This runs regardless of whether the primary field was already in $row, because
            // multiple link columns can share the same `field` but reference different label paths.
            if (($column['type'] ?? null) === 'link') {
                // Legacy single-field label
                if (!empty($column['label_field'])) {
                    $labelField = $column['label_field'];
                    if (!array_key_exists($labelField, $row)) {
                        $row[$labelField] = $this->getFieldValue($item, $labelField);
                    }
                }
                // Multi-line label: inject every line's field so the frontend can render them
                foreach ($column['label_lines'] ?? [] as $line) {
                    if (!empty($line['field']) && !array_key_exists($line['field'], $row)) {
                        $row[$line['field']] = $this->getFieldValue($item, $line['field']);
                    }
                }
            }
        }

        // Ensure fields referenced in badge conditions and aggregate where specs are present
        // in the row even when they are not declared as columns. This is required so that
        // filterChildrenForAggregate() can evaluate overdue conditions without an Eloquent model.
        foreach ($this->extraFieldsForConditions() as $auxField) {
            if (!array_key_exists($auxField, $row)) {
                $row[$auxField] = $this->getFieldValue($item, $auxField);
            }
        }

        // Ensure fields referenced in group_by.aggregates[*].field are present in the row.
        // Without this, aggregate fields that are not declared as visible columns are missing
        // from the mapped row and computeGroupAggregates() always returns 0.
        foreach ($this->extraFieldsForAggregates() as $aggField) {
            if (!array_key_exists($aggField, $row)) {
                $row[$aggField] = $this->getFieldValue($item, $aggField);
            }
        }

        // Before second pass: enrich $row with raw primary-model attributes for any
        // field that is referenced in an expression but NOT declared as a column.
        //
        // Context: evaluateExpression() builds the ExpressionLanguage variable map
        // from $row only.  If an expression operand (e.g. `deal_area`) is not a
        // declared column, it is absent from $row → ExpressionLanguage throws
        // "Variable X is not valid" → catch block returns 0 for the whole expression.
        //
        // To prevent this, we inject all scalar attributes of the primary $item into
        // $row as fallback.  Only keys that are not already in $row are written, so
        // computed/relation values set in the first pass are never overwritten.
        // Relation objects and array values are skipped — they cannot be used directly
        // in arithmetic expressions.
        foreach ($item->getAttributes() as $attrKey => $attrValue) {
            if (!array_key_exists($attrKey, $row) && !is_array($attrValue) && !is_object($attrValue)) {
                $row[$attrKey] = $attrValue;
            }
        }

        // Second pass: evaluate expressions
        foreach ($columns as $column) {
            if (isset($column['expression'])) {
                $field = $column['field'];
                $row[$field] = $this->evaluateExpression($column['expression'], $row);
            }
        }

        // Third pass: apply renderers
        foreach ($columns as $column) {
            if (isset($column['renderer'])) {
                $field = $column['field'];
                $row[$field] = $this->applyRenderer($column['renderer'], $item, $field, $row[$field], $index, $page, $perPage);
            }
        }

        // Fourth pass: compute badge annotations
        foreach ($columns as $column) {
            if (isset($column['badge'])) {
                $field = $column['field'];
                $badge = $this->computeBadge($column['badge'], $item, $row);
                if ($badge !== null) {
                    $row['_badge_' . $field] = $badge;
                }
            }
        }

        // Fifth pass: apply hide_zero — replace numeric zero with null for display.
        //
        // Purpose: EAV custom_attribute columns with value_type=number cast missing
        // values to 0.0 (safe arithmetic sentinel for expression evaluation).
        // When the column has hide_zero=true, those sentinel zeros are converted back
        // to null after all expression passes so the frontend renders an empty cell
        // instead of "0.00".
        //
        // hide_zero is intentionally applied AFTER the second (expression) pass:
        // expression columns that reference this field (e.g. paid_total depends on
        // paid_design) already consumed the numeric 0.0, so zeroing out here does not
        // break downstream arithmetic.
        //
        // Design constraints:
        //   - Only triggers for columns where hide_zero=true is explicitly set in config.
        //   - Replaces 0 / 0.0 / '0' / '0.0' / '0.00' with null; other falsy values
        //     (empty string, false) are left untouched — they have their own meaning.
        //   - Does not affect expression columns (they compute their value independently).
        foreach ($columns as $column) {
            if (!empty($column['hide_zero'])) {
                $field = $column['field'];
                if (!array_key_exists($field, $row)) {
                    continue;
                }
                $v = $row[$field];
                // Cast to float for the zero-check to handle '0', '0.0', '0.00' strings.
                if ($v !== null && is_numeric($v) && (float) $v === 0.0) {
                    $row[$field] = null;
                }
            }
        }

        return $row;
    }

    /**
     * Resolve a concat_relation column value by traversing the relation dot-path
     * and collecting the leaf field values into a single separator-joined string.
     *
     * Config keys:
     *   relation    (string)  — dot-path from the primary model to the eager-loaded
     *                           relation chain. Example: 'estateTagsRelation.tags'
     *   field       (string)  — row key name in the output (e.g. 'tags').
     *   value_field (string)  — attribute name on the FINAL related model to collect.
     *                           Example: 'tags_name'
     *   separator   (string)  — glue string. Default: ', '
     *
     * The relation must already be eager-loaded (extractRelations() handles this).
     * If the relation returns null or an empty collection, '' is returned.
     */
    protected function resolveConcatRelation(Model $item, array $column): string
    {
        $relationPath = $column['relation']    ?? null;
        $leafField    = $column['value_field'] ?? null;
        $separator    = $column['separator']   ?? ', ';

        if (!$relationPath || !$leafField || !is_string($leafField)) {
            return '';
        }

        // Walk the relation path to reach the collection.
        // e.g. 'estateTagsRelation.tags' → $item->estateTagsRelation (Collection<EstateTags>)
        //      then each item → ->tags (Tags model)
        $parts = explode('.', $relationPath);
        $collection = $item;

        foreach ($parts as $part) {
            if ($collection === null) {
                return '';
            }

            if ($collection instanceof \Illuminate\Database\Eloquent\Model) {
                $collection = $collection->{$part};
            } elseif ($collection instanceof \Illuminate\Support\Collection
                   || $collection instanceof \Illuminate\Database\Eloquent\Collection) {
                // Fan out: map each element through the next relation hop
                $collection = $collection->map(fn($el) => $el instanceof \Illuminate\Database\Eloquent\Model
                    ? $el->{$part}
                    : null
                )->filter()->values();
                // Flatten one level if the hop returned collections (BelongsToMany scenario)
                if ($collection->first() instanceof \Illuminate\Support\Collection
                    || $collection->first() instanceof \Illuminate\Database\Eloquent\Collection) {
                    $collection = $collection->flatten(1);
                }
            } else {
                return '';
            }
        }

        // $collection is now the final collection of related models (or a single model / null)
        if ($collection === null) {
            return '';
        }

        if ($collection instanceof \Illuminate\Database\Eloquent\Model) {
            $val = $collection->{$leafField};
            return $val !== null ? (string) $val : '';
        }

        if ($collection instanceof \Illuminate\Support\Collection
            || $collection instanceof \Illuminate\Database\Eloquent\Collection) {
            return $collection
                ->map(fn($el) => ($el instanceof \Illuminate\Database\Eloquent\Model)
                    ? $el->{$leafField}
                    : null
                )
                ->filter(fn($v) => $v !== null && $v !== '')
                ->values()
                ->implode($separator);
        }

        return '';
    }

    // =========================================================================
    // payment_schedule column helpers
    // =========================================================================

    /**
     * Return all column configs whose type is 'payment_schedule'.
     *
     * @return list<array>
     */
    protected function getPaymentScheduleColumns(): array
    {
        return array_values(array_filter(
            $this->config['columns'] ?? [],
            fn($col) => ($col['type'] ?? null) === 'payment_schedule'
        ));
    }

    /**
     * Build a [primaryKey => schedulePayload] map for all models on the current page.
     *
     * The method issues at most ONE query per payment_schedule column declaration.
     * All deal IDs on the page are gathered first; then a single
     *   SELECT … FROM finances WHERE deal_id IN (…) AND types_id IN (…) AND status IN (…)
     * retrieves every relevant Finance row, which is then grouped in PHP.
     *
     * If there are no payment_schedule columns or the paginator collection is empty,
     * an empty array is returned.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $collection  Items from paginator
     * @return array<int|string, array|null>  primaryKey → schedule payload
     */
    protected function buildPaymentScheduleMap(\Illuminate\Database\Eloquent\Collection $collection): array
    {
        $psColumns = $this->getPaymentScheduleColumns();
        if (empty($psColumns) || $collection->isEmpty()) {
            return [];
        }

        // We only support one payment_schedule column per report for now.
        // If multiple are declared we iterate each independently (keys differ by `field`).
        $combinedMap = [];

        foreach ($psColumns as $column) {
            $field        = $column['field'];
            $payments     = $column['payments'] ?? [];
            $relationName = $payments['relation'] ?? 'finances';
            $typesIds     = $payments['types_id']     ?? [3786, 3788];
            $statusPaid   = (int) ($payments['status_paid'] ?? 1);
            $statusDue    = (int) ($payments['status_due']  ?? 3);

            // Validate relation name (no injection via config).
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
                try {
                    Log::warning('payment_schedule: unsafe relation name — skipped', ['field' => $field]);
                } catch (\Throwable) {}
                continue;
            }

            // Validate that the relation exists on the primary model and is HasMany.
            if (!method_exists($this->modelInstance, $relationName)) {
                try {
                    Log::warning('payment_schedule: relation not found on model — skipped', [
                        'field'    => $field,
                        'relation' => $relationName,
                        'model'    => get_class($this->modelInstance),
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            try {
                $relationObj = $this->modelInstance->{$relationName}();
            } catch (\Throwable $e) {
                try {
                    Log::warning('payment_schedule: failed to resolve relation — skipped', [
                        'field' => $field, 'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            if (!($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany)) {
                try {
                    Log::warning('payment_schedule: relation must be HasMany — skipped', [
                        'field'    => $field,
                        'relation' => $relationName,
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            // Collect primary-key values for all items on the page.
            $pkValues = $collection->map(fn($item) => $item->getKey())
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            if (empty($pkValues)) {
                continue;
            }

            $fkColumn    = $relationObj->getForeignKeyName();      // e.g. deal_id
            $relatedTable = $relationObj->getRelated()->getTable(); // e.g. finances

            // Whitelist types_id values (must be integers).
            $safeTypesIds = array_values(array_filter(
                (array) $typesIds,
                fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))
            ));
            $safeTypesIds = array_map('intval', $safeTypesIds);

            // Single SELECT for all deals on this page.
            $rows = \Illuminate\Support\Facades\DB::connection('macrodata')
                ->table($relatedTable)
                ->whereIn($fkColumn, $pkValues)
                ->when(!empty($safeTypesIds), fn($q) => $q->whereIn('types_id', $safeTypesIds))
                ->whereIn('status', [$statusPaid, $statusDue])
                ->orderBy($fkColumn)
                ->orderBy('date_to')
                ->get(['id', $fkColumn, 'date_to', 'summa', 'status']);

            // Group rows by FK value and assemble the schedule payload.
            $grouped = [];
            foreach ($rows as $row) {
                $fkVal = $row->{$fkColumn};
                $grouped[$fkVal][] = $row;
            }

            $scheduleMap = [];
            foreach ($pkValues as $pk) {
                $finRows  = $grouped[$pk] ?? [];
                $paidTotal = 0.0;
                $dueTotal  = 0.0;
                $items     = [];

                foreach ($finRows as $fin) {
                    $summa  = (float) ($fin->summa ?? 0);
                    $status = (int)   ($fin->status ?? 0);
                    $isPaid = ($status === $statusPaid);
                    $isDue  = ($status === $statusDue);

                    if ($isPaid) {
                        $paidTotal += $summa;
                    }
                    if ($isDue) {
                        $dueTotal += $summa;
                    }

                    // Format date_to as Y-m-d.
                    $dateRaw = $fin->date_to ?? null;
                    $dateStr = null;
                    if ($dateRaw !== null) {
                        try {
                            $dateStr = \Carbon\Carbon::parse($dateRaw)->format('Y-m-d');
                        } catch (\Throwable) {
                            $dateStr = null;
                        }
                    }

                    $items[] = [
                        'date' => $dateStr,
                        'paid' => $isPaid ? $summa : null,
                        'due'  => $isDue  ? $summa : null,
                    ];
                }

                $scheduleMap[$pk] = [
                    'paid_total' => $paidTotal,
                    'due_total'  => $dueTotal,
                    'items'      => $items,
                ];
            }

            // Merge this column's map into the combined map (keyed by column field).
            // When there is only one payment_schedule column, $combinedMap === $scheduleMap.
            // Multiple columns: each row gets the schedule under its own field key.
            // We store by PK here; mapRow() reads by PK and assigns to column field.
            if (empty($combinedMap)) {
                $combinedMap = $scheduleMap;
            } else {
                // Merge per-pk (multiple ps columns share the same pk space).
                foreach ($scheduleMap as $pk => $schedule) {
                    $combinedMap[$pk] = $schedule;
                }
            }
        }

        return $combinedMap;
    }

    /**
     * Get field value from model, supporting nested relations
     */
    protected function getFieldValue(Model $item, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $item->{$field};
        }

        $parts = explode('.', $field);
        $value = $item;

        foreach ($parts as $part) {
            if ($value === null) {
                return null;
            }

            if (is_object($value)) {
                $value = $value->{$part} ?? null;
            } elseif (is_array($value)) {
                $value = $value[$part] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Apply custom renderer to transform raw value for display
     */
    protected function applyRenderer(string $renderer, Model $item, string $field, mixed $value, int $index, int $page, int $perPage): mixed
    {
        return match ($renderer) {
            'row_number' => ($page - 1) * $perPage + $index + 1,

            'tags_join' => collect($value)->map(fn($tag) => $tag->tags?->tags_name)->filter()->implode(', '),

            'area_range' => collect($value)
                ->filter(fn($attr) => ($attr->attr_name ?? null) === 'estate_area_range')
                ->sortBy('attr_value')
                ->pluck('attr_value')
                ->pipe(fn($values) => $values->count() >= 2
                    ? rtrim(rtrim($values->first(), '0'), '.') . ' - ' . rtrim(rtrim($values->last(), '0'), '.')
                    : ($values->first() ? rtrim(rtrim($values->first(), '0'), '.') : null)
                ),

            'paid_status' => match ($item->status ?? null) {
                1 => false,  // не оплачено
                3 => true,   // оплачено
                default => null,
            },

            default => $value,
        };
    }

    /**
     * Register the whitelist of helper functions available inside `expression`
     * columns evaluated by {@see evaluateExpression()}.
     *
     * These are pure PHP callbacks (no SQL is generated, MacroData stays read-only)
     * operating over values already fetched into the row by {@see mapRow()}.
     * They cover the common "days since / days until a date" use cases for a
     * developer dashboard (days in reservation, days overdue, age of a deal, …)
     * which previously silently evaluated to 0 because date strings were coerced
     * to 0 and no date functions existed.
     *
     * Available functions (use exactly these names in `expression`):
     *   today()                  → today's date as 'Y-m-d' string
     *   now()                    → current datetime as 'Y-m-d H:i:s' string
     *   days_since(date)         → whole days from `date` until today (today - date).
     *                              Positive for past dates. null/unparseable → null.
     *   days_until(date)         → whole days from today until `date` (date - today).
     *                              Positive for future dates. null/unparseable → null.
     *   date_diff_days(a, b)     → whole days b - a (a, b are dates). null on bad input.
     *   coalesce(value, default) → returns `value` unless it is null, then `default`.
     *                              Handy guard for the above (e.g. coalesce(days_since(d), 0)).
     */
    protected function registerExpressionFunctions(): void
    {
        $parse = static fn ($value): ?Carbon => self::parseExpressionDate($value);

        $this->expressionLanguage->register(
            'today',
            fn () => "(new \\Carbon\\Carbon())->toDateString()",
            static fn ($_args) => Carbon::today()->toDateString()
        );

        $this->expressionLanguage->register(
            'now',
            fn () => "(new \\Carbon\\Carbon())->toDateTimeString()",
            static fn ($_args) => Carbon::now()->toDateTimeString()
        );

        $this->expressionLanguage->register(
            'days_since',
            fn ($date) => "/* days_since */ {$date}",
            static function ($_args, $date) use ($parse): ?int {
                $parsed = $parse($date);
                if ($parsed === null) {
                    return null;
                }
                // today - date → positive for past dates.
                return (int) $parsed->copy()->startOfDay()->diffInDays(Carbon::today(), false);
            }
        );

        $this->expressionLanguage->register(
            'days_until',
            fn ($date) => "/* days_until */ {$date}",
            static function ($_args, $date) use ($parse): ?int {
                $parsed = $parse($date);
                if ($parsed === null) {
                    return null;
                }
                // date - today → positive for future dates.
                return (int) Carbon::today()->diffInDays($parsed->copy()->startOfDay(), false);
            }
        );

        $this->expressionLanguage->register(
            'date_diff_days',
            fn ($a, $b) => "/* date_diff_days */ {$a}, {$b}",
            static function ($_args, $a, $b) use ($parse): ?int {
                $from = $parse($a);
                $to   = $parse($b);
                if ($from === null || $to === null) {
                    return null;
                }
                // b - a → positive when b is later than a.
                return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false);
            }
        );

        $this->expressionLanguage->register(
            'coalesce',
            fn ($value, $default) => "({$value} ?? {$default})",
            static fn ($_args, $value, $default) => $value ?? $default
        );
    }

    /**
     * Parse a raw field value into a Carbon date, or null if it is not a date.
     *
     * Accepts Carbon instances, date strings ('Y-m-d', 'Y-m-d H:i:s', …) and
     * DateTimeInterface. Numeric values and empty strings are treated as
     * non-dates (return null) so that arithmetic-only expressions are unaffected.
     */
    protected static function parseExpressionDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        // Reject empty strings, the MySQL zero-date and pure numbers.
        if ($trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '0000-00-00 00:00:00' || is_numeric($trimmed)) {
            return null;
        }

        try {
            return Carbon::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Evaluate a simple expression (arithmetic and/or date helpers) using Symfony
     * ExpressionLanguage.
     *
     * Variable coercion:
     *   - numeric values  → float (so arithmetic over aggregate aliases works)
     *   - date-like values → kept as 'Y-m-d H:i:s' string, consumable by the
     *                        registered date functions (days_since, date_diff_days, …)
     *   - everything else  → 0 (preserves the legacy safe-null arithmetic behaviour)
     *
     * On any evaluation error the expression yields 0 (legacy behaviour) — except
     * that date helpers themselves return null on unparseable input, which lets
     * callers distinguish "no date" from "0 days".
     */
    protected function evaluateExpression(string $expression, array $values): mixed
    {
        // Replace dots with underscores in variable names for ExpressionLanguage
        $variables = [];
        foreach ($values as $field => $value) {
            $safeKey = str_replace('.', '_', $field);

            // Null values must stay null so that coalesce(null, fallback) works
            // correctly.  Previously nulls were coerced to 0 (float), which caused
            // `coalesce(deal_date, signed_date)` to return 0 instead of signed_date
            // when deal_date is NULL — because PHP `??` tests for null, not falsy.
            if ($value === null) {
                $variables[$safeKey] = null;
                continue;
            }

            if (is_numeric($value)) {
                $variables[$safeKey] = (float) $value;
                continue;
            }

            // Pass date-like values through so date functions can consume them.
            $date = self::parseExpressionDate($value);
            if ($date !== null) {
                $variables[$safeKey] = $date->toDateTimeString();
                continue;
            }

            // Non-numeric, non-date string → 0 (legacy safe-null arithmetic behaviour).
            $variables[$safeKey] = 0;
        }

        // Also replace dots in expression
        $safeExpression = str_replace('.', '_', $expression);

        try {
            return $this->expressionLanguage->evaluate($safeExpression, $variables);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Window-aggregate (per-row SQL window functions) logic
    // -------------------------------------------------------------------------

    /**
     * Allowed aggregate functions for window_aggregate columns (whitelist).
     */
    protected const WINDOW_AGG_FUNCTIONS = ['SUM', 'COUNT', 'AVG', 'MIN', 'MAX'];

    /**
     * Validate a column name to be safe for use in a raw SQL window expression.
     *
     * Accepts only simple SQL identifiers: [a-zA-Z_][a-zA-Z0-9_]*
     * Dot-notation (relation paths) is NOT allowed — window functions operate on
     * columns of the primary model's table only.
     *
     * @throws \InvalidArgumentException when the name is not a safe identifier
     */
    protected function assertSafeColumnName(string $name, string $context): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Unsafe column name '{$name}' in {$context}. " .
                'Only simple identifiers (letters, digits, underscores) are allowed. ' .
                'Dot-notation relation paths are not supported in window_aggregate.'
            );
        }
    }

    /**
     * Collect all window_aggregate column definitions from the current config.
     *
     * @return array[]  Array of window_aggregate column configs
     */
    protected function getWindowAggregateColumns(): array
    {
        return array_values(array_filter(
            $this->config['columns'] ?? [],
            fn($col) => ($col['type'] ?? null) === 'window_aggregate'
        ));
    }

    /**
     * Inject SQL window function SELECT expressions into the query for every
     * window_aggregate column in the report config.
     *
     * Each window_aggregate column produces a selectRaw() clause of the form:
     *
     *   SUM(`summa`) OVER (PARTITION BY `estate_sell_id`, `deal_id`) AS `cumulative_debt`
     *
     * The column alias equals the column's `field` value, so that getFieldValue() /
     * mapRow() can read it from the Eloquent model attributes without any changes.
     *
     * Security:
     *   - aggregate function is matched against a whitelist (WINDOW_AGG_FUNCTIONS).
     *   - aggregate field and partition fields are validated as safe SQL identifiers
     *     via assertSafeColumnName() (rejects dots, spaces, special chars).
     *   - No user input is accepted — values come from report.config (admin-only jsonb).
     *
     * This method is a no-op when:
     *   - There are no window_aggregate columns in the config.
     *
     * @param Builder $query  Base query (mutated in place via addSelect)
     */
    protected function applyWindowAggregateSelects(Builder $query): void
    {
        $windowColumns = $this->getWindowAggregateColumns();
        if (empty($windowColumns)) {
            return;
        }

        // Ensure the base query selects all primary-table columns before we add extras.
        // Without this, addSelect() on a fresh query would replace the implicit '*'.
        $query->addSelect($this->modelInstance->getTable() . '.*');

        foreach ($windowColumns as $column) {
            $alias     = $column['field'] ?? null;
            $aggConfig = $column['aggregate'] ?? [];

            if (!$alias) {
                continue;
            }

            $fn        = strtoupper($aggConfig['fn']    ?? 'SUM');
            $aggField  = $aggConfig['field']             ?? null;
            $partition = (array) ($aggConfig['partition'] ?? []);

            // Validate aggregate function against whitelist
            if (!in_array($fn, self::WINDOW_AGG_FUNCTIONS, true)) {
                try {
                    Log::warning('window_aggregate: unsupported fn — skipped', [
                        'column' => $alias,
                        'fn'     => $fn,
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            // Validate all field names — reject dot-notation (relation paths)
            try {
                $this->assertSafeColumnName($alias, "window_aggregate field alias");
                if ($aggField !== null) {
                    $this->assertSafeColumnName($aggField, "window_aggregate.aggregate.field for '{$alias}'");
                }
                foreach ($partition as $partField) {
                    $this->assertSafeColumnName($partField, "window_aggregate.aggregate.partition for '{$alias}'");
                }
            } catch (\InvalidArgumentException $e) {
                try {
                    Log::warning('window_aggregate: unsafe column name — skipped', [
                        'column' => $alias,
                        'error'  => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            // ignore_date_filters: true — use a correlated subquery instead of a window
            // function so that only entity-type (non-date) filters are applied, while
            // date/period filters from the user are excluded from the aggregate scope.
            // This allows "cumulative total" columns to always reflect the full dataset
            // scoped to the same entity (partition fields), regardless of any date range
            // the user has selected on the primary listing.
            if (!empty($column['ignore_date_filters'])) {
                $subquerySql = $this->buildWindowIgnoreDateSubquery(
                    fn        : $fn,
                    aggField  : $aggField,
                    partition : $partition,
                    alias     : $alias
                );
                if ($subquerySql !== null) {
                    $query->addSelect(new \Illuminate\Database\Query\Expression("{$subquerySql} AS `{$alias}`"));
                }
                continue;
            }

            // Standard path: SQL window function OVER (PARTITION BY ...)
            // Build: SUM(`summa`) or COUNT(*)
            $innerExpr = ($fn === 'COUNT' || $aggField === null)
                ? 'COUNT(*)'
                : "{$fn}(`{$aggField}`)";

            // Build OVER clause
            if (!empty($partition)) {
                $partitionSql = implode(', ', array_map(fn($f) => "`{$f}`", $partition));
                $windowExpr   = "{$innerExpr} OVER (PARTITION BY {$partitionSql})";
            } else {
                // No partition = global aggregate over the entire result set
                $windowExpr = "{$innerExpr} OVER ()";
            }

            $query->addSelect(new \Illuminate\Database\Query\Expression("{$windowExpr} AS `{$alias}`"));
        }
    }

    /**
     * Build a correlated subquery for a window_aggregate column with ignore_date_filters=true.
     *
     * Instead of a SQL window function (which operates over the already-filtered result set,
     * including any date WHERE conditions), this builds a correlated subquery that:
     *   - Selects from the same primary model table
     *   - Applies global WHERE conditions (config.where) — always
     *   - Applies entity-type user filters (non-date columns) — always
     *   - Does NOT apply date/datetime/period filters from the user
     *   - Correlates on the partition fields (partition[0] = col, partition[1] = col, ...)
     *
     * Example output for cumulative_debt (partition=[estate_sell_id, deal_id]):
     *   (SELECT SUM(`wa`.`summa`)
     *    FROM `finances` `wa`
     *    WHERE `wa`.`status` = 3         ← from global where config
     *      AND `wa`.`types_id` IN (…)    ← from global where config
     *      AND `wa`.`estate_sell_id` = `finances`.`estate_sell_id`   ← partition correlation
     *      AND `wa`.`deal_id` = `finances`.`deal_id`                 ← partition correlation
     *      AND <entity filters only, no date_to filter>
     *   ) AS `cumulative_debt`
     *
     * Security:
     *   - All field names validated as safe SQL identifiers before this method is called.
     *   - Global where conditions are re-applied via applyGlobalWheres() on a sub-query builder.
     *   - Entity filters are re-applied via applyFilters() after stripping date fields.
     *   - No user input reaches raw SQL; PDO parameter binding used throughout.
     *
     * Returns null (and logs a warning) if partition is empty (no correlation possible).
     *
     * @param string   $fn        Aggregate function (uppercase, already validated)
     * @param ?string  $aggField  Field to aggregate (already validated)
     * @param string[] $partition Partition fields = correlation columns (already validated)
     * @param string   $alias     Column alias (for error logging)
     */
    protected function buildWindowIgnoreDateSubquery(
        string  $fn,
        ?string $aggField,
        array   $partition,
        string  $alias
    ): ?string {
        if (empty($partition)) {
            try {
                Log::warning('window_aggregate ignore_date_filters: empty partition — cannot correlate, skipped', [
                    'column' => $alias,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        $primaryTable = $this->modelInstance->getTable();
        $waAlias      = 'wa_' . $alias; // unique alias per column to avoid conflicts

        // Build inner expression: SUM(`wa`.`summa`) etc.
        $innerExpr = ($fn === 'COUNT' || $aggField === null)
            ? 'COUNT(*)'
            : "{$fn}(`{$waAlias}`.`{$aggField}`)";

        // Build a sub-query builder on the same connection/table using the alias.
        // We use a raw DB query (not Eloquent model newQuery) because the alias
        // `wa_<col>` is only meaningful as a string alias — we just need a clean builder
        // to collect WHERE bindings, then extract them as raw SQL + bindings.
        //
        // Strategy: clone a fresh query for the primary model, apply global wheres and
        // entity-only filters, then extract the WHERE SQL fragment and bindings to
        // inline them into the correlated subquery string.
        //
        // We cannot use Eloquent's paginate/get here because we are building a SELECT
        // expression, not executing a query. We extract the compiled WHERE clause.

        /** @var Builder $subQb */
        $subQb = $this->modelInstance->newQuery();

        // Apply global config wheres (e.g. status=3, types_id IN ...)
        $this->applyGlobalWheres($subQb);

        // Apply only entity-type (non-date) user filters.
        // Date-type filters are identified by the column type of each filter field.
        $this->applyFiltersIgnoringDates($subQb, $this->currentParams);

        // Extract WHERE bindings from the sub-query builder.
        // We compile to SQL, then strip the leading "SELECT * FROM `table`" part,
        // keeping only the WHERE ... portion. Then we re-alias the table reference.
        try {
            $compiled = $subQb->reorder()->toBase();
            $grammar  = $compiled->getGrammar();

            // Get the compiled WHERE SQL (without SELECT/FROM)
            $whereSql      = $grammar->compileWheres($compiled);
            $whereBindings = $compiled->getRawBindings()['where'] ?? [];
        } catch (\Throwable $e) {
            try {
                Log::warning('window_aggregate ignore_date_filters: failed to compile sub-query WHERE — falling back to window function', [
                    'column' => $alias,
                    'error'  => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
            return null;
        }

        // Replace table references with the subquery alias (`finances` → `wa_<alias>`)
        $whereSqlAliased = str_replace(
            ["`{$primaryTable}`.", "{$primaryTable}."],
            ["`{$waAlias}`.", "`{$waAlias}`."],
            $whereSql
        );

        // Resolve PDO bindings into safe SQL literals.
        // We inline them because we are building a raw SQL string (not a prepared statement).
        $inlinedWhere = $this->inlineBindings($whereSqlAliased, $whereBindings);

        // Build correlation conditions (partition fields)
        $correlations = implode(' AND ', array_map(
            fn($f) => "`{$waAlias}`.`{$f}` = `{$primaryTable}`.`{$f}`",
            $partition
        ));

        // Assemble full WHERE (global+entity conditions come from $inlinedWhere,
        // then we append the partition correlation)
        if ($inlinedWhere !== '' && strtolower(ltrim($inlinedWhere)) !== 'where') {
            // $inlinedWhere already contains "where ..." — append AND correlation
            $fullWhere = $inlinedWhere . " AND ({$correlations})";
        } else {
            $fullWhere = "where ({$correlations})";
        }

        return "(SELECT {$innerExpr} FROM `{$primaryTable}` `{$waAlias}` {$fullWhere})";
    }

    /**
     * Inline PDO parameter bindings into a compiled SQL WHERE string.
     *
     * The compiled WHERE string uses `?` placeholders. We replace them in order
     * with the binding values, quoting strings and escaping them for safe injection.
     *
     * This is intentionally limited to the date-filter subquery context where all
     * values originate from report.config (admin-only jsonb) or Carbon-resolved
     * date constants — never from raw user search input.
     *
     * @param string $sql      Compiled SQL with `?` placeholders
     * @param array  $bindings Ordered binding values
     * @return string SQL with placeholders replaced by inline literals
     */
    protected function inlineBindings(string $sql, array $bindings): string
    {
        foreach ($bindings as $binding) {
            $literal = $this->bindingToSqlLiteral($binding);
            // Replace only the first occurrence of `?`
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                $sql = substr($sql, 0, $pos) . $literal . substr($sql, $pos + 1);
            }
        }
        return $sql;
    }

    /**
     * Convert a single PDO binding value to a safe SQL literal string.
     *
     * - null      → NULL
     * - bool      → 1 / 0
     * - numeric   → unquoted number (cast to string)
     * - string    → single-quoted with internal single-quotes escaped
     * - Carbon    → quoted date string
     * - other     → cast to string, then quoted
     */
    protected function bindingToSqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof Carbon) {
            $value = $value->toDateTimeString();
        }
        $str = (string) $value;
        return "'" . str_replace("'", "''", $str) . "'";
    }

    /**
     * Return the set of column fields that are date-type (date / datetime) in the
     * current report config.
     *
     * Used to identify which user filter keys should be excluded when building the
     * ignore_date_filters correlated subquery (Фича A).
     *
     * A field is considered a date filter if:
     *   - Its column type is 'date' or 'datetime'
     *   - OR it has filter_default that looks like a date_range (has 'from'/'to' keys
     *     with date-like values) — covers columns where type is overridden but the
     *     filter is still a date range
     *
     * @return string[]  Array of field names (keys of $params['filters'])
     */
    protected function collectDateFilterFields(): array
    {
        $dateFields = [];
        foreach ($this->config['columns'] ?? [] as $column) {
            $field = $column['field'] ?? null;
            if (!$field) {
                continue;
            }
            $type = $column['type'] ?? 'text';
            if (in_array($type, ['date', 'datetime'], true)) {
                $dateFields[] = $field;
            }
        }
        return array_unique($dateFields);
    }

    /**
     * Apply user filters to a query, EXCLUDING all date/datetime type filter fields.
     *
     * This is the entity-only variant used for buildWindowIgnoreDateSubquery().
     * The logic is identical to applyFilters() except that any filter whose
     * corresponding column is of type date/datetime is skipped entirely.
     *
     * Only user-supplied filters ($params['filters']) are considered. Global WHERE
     * conditions (config.where) are applied separately via applyGlobalWheres().
     *
     * @param Builder $query  Query builder to apply filters to
     * @param array   $params Request params (same shape as applyFilters receives)
     */
    protected function applyFiltersIgnoringDates(Builder $query, array $params): void
    {
        $dateFields = array_flip($this->collectDateFilterFields());

        $userFilters = $params['filters'] ?? [];
        if (empty($userFilters)) {
            return;
        }

        // Temporarily remove date-field filters, then apply the remaining filters normally.
        $entityFilters = array_diff_key($userFilters, $dateFields);

        if (empty($entityFilters)) {
            return;
        }

        $this->applyFilters($query, ['filters' => $entityFilters]);
    }

    // -------------------------------------------------------------------------
    // Relation-aggregate (per-row correlated subqueries) logic
    // -------------------------------------------------------------------------

    /**
     * Allowed aggregate functions for relation_aggregate columns (whitelist).
     *
     * COUNT, GROUP_CONCAT — single-hop, no value_field required for COUNT.
     * SUM, AVG, MIN, MAX  — single-hop or through-chain; require `value_field`.
     */
    protected const RELATION_AGG_FUNCTIONS = ['COUNT', 'GROUP_CONCAT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * Collect all relation_aggregate column definitions from the current config.
     *
     * @return array[]
     */
    protected function getRelationAggregateColumns(): array
    {
        return array_values(array_filter(
            $this->config['columns'] ?? [],
            fn($col) => ($col['type'] ?? null) === 'relation_aggregate'
        ));
    }

    /**
     * Inject correlated subquery SELECT expressions into the query for every
     * relation_aggregate column in the report config.
     *
     * Each relation_aggregate column produces a selectRaw() clause of the form:
     *
     *   COUNT (1-hop):
     *     (SELECT COUNT(*) FROM `tasks`
     *      WHERE `tasks`.`estate_id` = `estate_buys`.`estate_buy_id`
     *        AND <where conditions>) AS `scheduled_meetings`
     *
     *   SUM (1-hop, requires value_field):
     *     (SELECT SUM(`estate_sells`.`estate_area`)
     *      FROM `estate_sells`
     *      WHERE `estate_sells`.`house_id` = `estate_houses`.`house_id`) AS `total_area`
     *
     *   SUM (through-chain 2-hop: Houses → Sells → Deals):
     *     (SELECT SUM(`d`.`deal_sum`)
     *      FROM `estate_sells` `s`
     *      JOIN `estate_deals` `d` ON `d`.`estate_sell_id` = `s`.`estate_sell_id`
     *      WHERE `s`.`house_id` = `estate_houses`.`house_id`
     *        AND <through_where/leaf where>) AS `sold_total`
     *
     *   SUM (through-chain 3-hop: Houses → Sells → Deals → Finances):
     *     (SELECT SUM(`f`.`summa`)
     *      FROM `estate_sells` `s`
     *      JOIN `estate_deals` `d` ON `d`.`estate_sell_id` = `s`.`estate_sell_id`
     *      JOIN `finances` `f` ON `f`.`deal_id` = `d`.`deal_id`
     *      WHERE `s`.`house_id` = `estate_houses`.`house_id`
     *        AND <leaf where>) AS `paid_total`
     *
     *   GROUP_CONCAT:
     *     (SELECT GROUP_CONCAT(DISTINCT `users`.`name` SEPARATOR ', ')
     *      FROM `estate_meetings`
     *      [JOIN `users` ON `users`.`id` = `estate_meetings`.`users_id`]
     *      WHERE `estate_meetings`.`estate_buy_id` = `estate_buys`.`estate_buy_id`) AS `meeting_managers`
     *
     * Column config shape (relation_aggregate):
     *   field          (string)        — output alias (row key)
     *   type           (string)        — 'relation_aggregate'
     *   aggregate:
     *     function     (string)        — 'count' | 'sum' | 'avg' | 'min' | 'max' | 'group_concat'
     *     relation     (string)        — Eloquent relation name on the primary model (1st hop)
     *     through      (string[])      — optional array of additional hop relation names on each
     *                                    intermediate model. Each name must be a valid Eloquent
     *                                    relation (hasMany/hasOne/belongsTo accepted for JOIN resolution).
     *                                    Example: ['estateDeals'] or ['estateDeals', 'finances']
     *     value_field  (string|null)   — leaf column to aggregate (required for SUM/AVG/MIN/MAX/GROUP_CONCAT).
     *                                    Resolved on the last hop's related table (or join-table for GROUP_CONCAT).
     *     join         (array|null)    — optional JOIN for GROUP_CONCAT across a bridge table:
     *                                    { table, alias?, on_local, on_foreign }
     *     where        (array|null)    — WHERE conditions on the leaf table (same format as applyStructuredConditions).
     *                                    OR expression-type: { type:'expression', expr:'...' }
     *     through_where (array[])      — optional per-hop WHERE conditions (indexed same as `through`).
     *                                    Each element is an array of conditions applied to the JOIN.
     *     distinct     (bool)          — DISTINCT modifier for GROUP_CONCAT (default false)
     *     separator    (string)        — GROUP_CONCAT separator (default ', ')
     *
     * Security:
     *   - Relation names are validated via method_exists() on each model in the chain.
     *   - Table/column names from Eloquent internals — never from user input.
     *   - value_field and join column names are validated as safe SQL identifiers.
     *   - WHERE conditions are built through the same applyStructuredConditions machinery
     *     (structured) or via ExpressionSqlTranslator (expression type).
     *   - No user-submitted data reaches the subquery SQL.
     *
     * This method is a no-op when:
     *   - There are no relation_aggregate columns in the config.
     *
     * @param Builder $query  Base query (mutated in place via addSelect)
     */
    protected function applyRelationAggregateSelects(Builder $query): void
    {
        $raColumns = $this->getRelationAggregateColumns();
        if (empty($raColumns)) {
            return;
        }

        // Ensure the base query selects all primary-table columns before we add extras.
        $query->addSelect($this->modelInstance->getTable() . '.*');

        foreach ($raColumns as $column) {
            $alias     = $column['field'] ?? null;
            $aggConfig = $column['aggregate'] ?? [];

            if (!$alias) {
                continue;
            }

            // Validate alias
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                try {
                    Log::warning('relation_aggregate: unsafe alias — skipped', ['field' => $alias]);
                } catch (\Throwable) {}
                continue;
            }

            $fn           = strtoupper($aggConfig['function'] ?? 'COUNT');
            $relationName = $aggConfig['relation'] ?? null;

            if (!in_array($fn, self::RELATION_AGG_FUNCTIONS, true)) {
                try {
                    Log::warning('relation_aggregate: unsupported function — skipped', ['field' => $alias, 'fn' => $fn]);
                } catch (\Throwable) {}
                continue;
            }

            if (!$relationName || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
                try {
                    Log::warning('relation_aggregate: invalid relation name — skipped', ['field' => $alias]);
                } catch (\Throwable) {}
                continue;
            }

            // Resolve relation metadata via Eloquent reflection
            if (!method_exists($this->modelInstance, $relationName)) {
                try {
                    Log::warning('relation_aggregate: relation not found on model — skipped', [
                        'field'    => $alias,
                        'relation' => $relationName,
                        'model'    => get_class($this->modelInstance),
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            try {
                $relationObj   = $this->modelInstance->{$relationName}();
                $relatedModel  = $relationObj->getRelated();
                $relatedTable  = $relatedModel->getTable();
            } catch (\Throwable $e) {
                try {
                    Log::warning('relation_aggregate: failed to resolve relation — skipped', [
                        'field' => $alias, 'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            // If `through` chain is specified, delegate to the through-subquery builder.
            $throughChain = $aggConfig['through'] ?? [];
            if (!empty($throughChain)) {
                $subquerySql = $this->buildThroughSubquery(
                    fn          : $fn,
                    firstRelObj : $relationObj,
                    firstRelTable: $relatedTable,
                    firstRelModel: $relatedModel,
                    throughChain: $throughChain,
                    aggConfig   : $aggConfig,
                    alias       : $alias
                );
            } else {
                // Determine FK/PK from relation type
                [$fkColumn, $pkColumn, $primaryTable] = $this->resolveRelationKeys($relationObj);
                if ($fkColumn === null) {
                    try {
                        Log::warning('relation_aggregate: unsupported relation type (only hasMany/hasOne allowed) — skipped', [
                            'field' => $alias,
                        ]);
                    } catch (\Throwable) {}
                    continue;
                }

                // Build the correlated subquery SQL
                $subquerySql = $this->buildCorrelatedSubquery(
                    fn          : $fn,
                    relatedTable: $relatedTable,
                    fkColumn    : $fkColumn,
                    pkColumn    : $pkColumn,
                    primaryTable: $primaryTable,
                    aggConfig   : $aggConfig,
                    alias       : $alias
                );
            }

            if ($subquerySql === null) {
                continue;
            }

            $query->addSelect(new \Illuminate\Database\Query\Expression("{$subquerySql} AS `{$alias}`"));
        }
    }

    // =========================================================================
    // custom_attribute column type
    // =========================================================================

    /**
     * Allowed `attr_source` values for custom_attribute columns.
     *
     * - 'estate_attributes'  — admin-defined EAV attributes, keyed by attr_id (int).
     *                          Scoped by `entity` column in estate_attributes.
     * - 'estate_sells_attr'  — built-in per-unit attributes, keyed by attr_name (string).
     *                          No entity column — FK is estate_sell_id directly.
     */
    protected const CUSTOM_ATTR_SOURCES = ['estate_attributes', 'estate_sells_attr'];

    /**
     * Allowed `entity` values for custom_attribute columns that use
     * attr_source='estate_attributes'.
     *
     * Maps entity token → primary-model column that holds that entity's PK.
     * The column name is used in the correlated subquery WHERE clause.
     *
     * Design rule: this mapping is a whitelist. Any entity/column pair outside
     * this map is rejected during prevalidation (and silently skipped at runtime).
     * New entity types require a code-level addition here — not user-supplied strings.
     */
    protected const CUSTOM_ATTR_ENTITY_PK_MAP = [
        'estate_sell'  => 'estate_sell_id',
        'estate_deal'  => 'deal_id',
        'estate_buy'   => 'estate_buy_id',
        'contacts'     => 'contacts_buy_id',  // most primary models link contacts via contacts_buy_id
        'promos'       => 'promos_id',
    ];

    /**
     * Collect all custom_attribute column definitions from the current config.
     *
     * @return array[]
     */
    protected function getCustomAttributeColumns(): array
    {
        return array_values(array_filter(
            $this->config['columns'] ?? [],
            fn($col) => ($col['type'] ?? null) === 'custom_attribute'
        ));
    }

    /**
     * Inject correlated subquery SELECT expressions into the query for every
     * custom_attribute column in the report config.
     *
     * Two sources are supported:
     *
     *   1. attr_source='estate_attributes' (admin-defined EAV):
     *      (SELECT ea.attr_value
     *       FROM `estate_attributes` ea
     *       WHERE ea.entity = 'estate_deal'
     *         AND ea.entity_id = `estate_deals`.`deal_id`
     *         AND ea.attr_id = 3
     *       LIMIT 1) AS `nationality`
     *
     *   2. attr_source='estate_sells_attr' (built-in per-unit):
     *      (SELECT esa.attr_value
     *       FROM `estate_sells_attr` esa
     *       WHERE esa.estate_sell_id = `estate_deals`.`estate_sell_id`
     *         AND esa.attr_name = 'estate_area_balcony'
     *       LIMIT 1) AS `balcony_area`
     *
     * Security:
     *   - attr_source and entity values are validated against whitelists before SQL.
     *   - entity_id PK column name comes from CUSTOM_ATTR_ENTITY_PK_MAP (static, not user input).
     *   - attr_id is cast to int; attr_name is validated as a safe SQL identifier.
     *   - Primary table/column names come from Eloquent internals — not user input.
     *   - No user-supplied string value ever reaches the subquery SQL unquoted.
     *
     * This method is a no-op when there are no custom_attribute columns in the config.
     *
     * @param Builder $query  Base query (mutated in place via addSelect)
     */
    protected function applyCustomAttributeSelects(Builder $query): void
    {
        $columns = $this->getCustomAttributeColumns();
        if (empty($columns)) {
            return;
        }

        // Ensure the base query selects all primary-table columns first.
        $query->addSelect($this->modelInstance->getTable() . '.*');

        $primaryTable = $this->modelInstance->getTable();

        // We need a PDO connection for quoting — re-use the existing connection that
        // ConnectionService already established for the primary query.
        /** @var \PDO|null $pdo */
        $pdo = null;
        try {
            $pdo = $this->modelInstance->getConnection()->getPdo();
        } catch (\Throwable $e) {
            try {
                Log::warning('custom_attribute: could not obtain PDO for quoting — columns skipped', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {}
            return;
        }

        foreach ($columns as $column) {
            $alias      = $column['field'] ?? null;
            $attrSource = $column['attr_source'] ?? null;

            if (!$alias) {
                continue;
            }

            // Validate alias as safe SQL identifier.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                try {
                    Log::warning('custom_attribute: unsafe alias — skipped', ['field' => $alias]);
                } catch (\Throwable) {}
                continue;
            }

            // Validate attr_source against whitelist.
            if (!in_array($attrSource, self::CUSTOM_ATTR_SOURCES, true)) {
                try {
                    Log::warning('custom_attribute: unknown attr_source — skipped', [
                        'field'       => $alias,
                        'attr_source' => $attrSource,
                    ]);
                } catch (\Throwable) {}
                continue;
            }

            $subquerySql = match ($attrSource) {
                'estate_attributes' => $this->buildEstateAttributesSubquery(
                    $column, $alias, $primaryTable, $pdo
                ),
                'estate_sells_attr' => $this->buildEstateSellsAttrSubquery(
                    $column, $alias, $primaryTable, $pdo
                ),
            };

            if ($subquerySql === null) {
                continue;
            }

            $query->addSelect(new \Illuminate\Database\Query\Expression(
                "{$subquerySql} AS `{$alias}`"
            ));
        }
    }

    /**
     * Build a correlated subquery for an estate_attributes row.
     *
     * Requires:
     *   - column['entity']  — one of CUSTOM_ATTR_ENTITY_PK_MAP keys (whitelist).
     *   - column['attr_id'] — integer (used as = binding), OR
     *     column['attr_name'] — validated safe identifier matched against attr_value
     *                           via a sub-select on estate_attributes_names.
     *
     * The entity_id on the primary table (CUSTOM_ATTR_ENTITY_PK_MAP[$entity]) must
     * exist as a column on the primary model's table — it is used in the WHERE
     * correlation clause and comes from the static map, never from user input.
     *
     * Returns null (and logs a warning) on any validation failure.
     */
    protected function buildEstateAttributesSubquery(
        array  $column,
        string $alias,
        string $primaryTable,
        \PDO   $pdo
    ): ?string {
        $entity = $column['entity'] ?? null;

        // Validate entity against whitelist.
        if (!is_string($entity) || !isset(self::CUSTOM_ATTR_ENTITY_PK_MAP[$entity])) {
            try {
                Log::warning('custom_attribute(estate_attributes): unknown entity — skipped', [
                    'field'  => $alias,
                    'entity' => $entity,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        // The PK column on the primary table that holds the entity's FK value.
        $pkColumn = self::CUSTOM_ATTR_ENTITY_PK_MAP[$entity];  // static, safe

        // Determine identifier clause: attr_id (int) or attr_name (identifier).
        $attrId   = isset($column['attr_id'])   ? (int) $column['attr_id']   : null;
        $attrName = isset($column['attr_name'])
            ? (is_string($column['attr_name']) ? trim($column['attr_name']) : null)
            : null;

        if ($attrId === null && $attrName === null) {
            try {
                Log::warning('custom_attribute(estate_attributes): neither attr_id nor attr_name specified — skipped', [
                    'field' => $alias,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        if ($attrId !== null) {
            // attr_id is an integer — bind as literal int (no SQL injection risk).
            $attrWhere = "ea.`attr_id` = {$attrId}";
        } else {
            // attr_name lookup via estate_attributes_names join:
            // We store human titles in estate_attributes_names.attr_title; the
            // attr_id FK is stored in estate_attributes.attr_id.
            // This path performs a sub-select by title, which may be slower.
            // Validate attr_name as a safe value: allow printable ASCII except
            // backtick, quote, backslash, semicolon. Use PDO::quote for the value.
            if (!preg_match('/^[A-Za-z0-9_.\- ]+$/', $attrName)) {
                try {
                    Log::warning('custom_attribute(estate_attributes): unsafe attr_name value — skipped', [
                        'field'     => $alias,
                        'attr_name' => $attrName,
                    ]);
                } catch (\Throwable) {}
                return null;
            }
            $quotedAttrName = $pdo->quote($attrName);
            $attrWhere = "ea.`attr_id` = (SELECT `id` FROM `estate_attributes_names` WHERE `attr_title` = {$quotedAttrName} LIMIT 1)";
        }

        // Quoted entity string — safe literal.
        $quotedEntity = $pdo->quote($entity);

        return <<<SQL
(SELECT ea.`attr_value`
 FROM `estate_attributes` ea
 WHERE ea.`entity` = {$quotedEntity}
   AND ea.`entity_id` = `{$primaryTable}`.`{$pkColumn}`
   AND {$attrWhere}
 LIMIT 1)
SQL;
    }

    /**
     * Build a correlated subquery for an estate_sells_attr row.
     *
     * Requires:
     *   - column['attr_name'] — validated safe SQL identifier (estate_area_balcony, etc.)
     *
     * The primary table must have an `estate_sell_id` column for correlation.
     * This is valid for EstateSells (directly) and for EstateDeals (has estate_sell_id FK).
     * For other primary models without estate_sell_id the subquery will silently return NULL.
     *
     * Returns null (and logs a warning) on any validation failure.
     */
    protected function buildEstateSellsAttrSubquery(
        array  $column,
        string $alias,
        string $primaryTable,
        \PDO   $pdo
    ): ?string {
        $attrName = isset($column['attr_name'])
            ? (is_string($column['attr_name']) ? trim($column['attr_name']) : null)
            : null;

        if (!$attrName) {
            try {
                Log::warning('custom_attribute(estate_sells_attr): attr_name is required — skipped', [
                    'field' => $alias,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        // Validate attr_name as a safe SQL identifier (estate_area_balcony style).
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $attrName)) {
            try {
                Log::warning('custom_attribute(estate_sells_attr): unsafe attr_name identifier — skipped', [
                    'field'     => $alias,
                    'attr_name' => $attrName,
                ]);
            } catch (\Throwable) {}
            return null;
        }

        $quotedAttrName = $pdo->quote($attrName);

        return <<<SQL
(SELECT esa.`attr_value`
 FROM `estate_sells_attr` esa
 WHERE esa.`estate_sell_id` = `{$primaryTable}`.`estate_sell_id`
   AND esa.`attr_name` = {$quotedAttrName}
 LIMIT 1)
SQL;
    }

    /**
     * Resolve FK/PK column names from a HasMany or HasOne relation object.
     *
     * Returns [fkColumn, pkColumn, primaryTable] on success.
     * Returns [null, null, null] for unsupported relation types.
     *
     * For HasMany/HasOne:
     *   fkColumn     — foreign key on the related table (e.g. `estate_id` in `tasks`)
     *   pkColumn     — local key on the primary table   (e.g. `estate_buy_id` in `estate_buys`)
     *   primaryTable — primary model table name
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function resolveRelationKeys(object $relationObj): array
    {
        $primaryTable = $this->modelInstance->getTable();

        if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany
            || $relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            // FK lives on the related table; local key lives on the primary model
            $fkColumn = $relationObj->getForeignKeyName();
            $pkColumn = $relationObj->getLocalKeyName();
            return [$fkColumn, $pkColumn, $primaryTable];
        }

        return [null, null, null];
    }

    /**
     * Build a correlated subquery SQL string for a relation_aggregate column (single hop).
     *
     * Generates:
     *   COUNT:        (SELECT COUNT(*) FROM `related_table` WHERE fk = pk AND <where>)
     *   SUM/AVG/MIN/MAX:
     *                 (SELECT SUM(`related_table`.`value_field`) FROM `related_table` WHERE fk = pk AND <where>)
     *   GROUP_CONCAT: (SELECT GROUP_CONCAT([DISTINCT] `join_alias`.`value_field` SEPARATOR 'sep')
     *                  FROM `related_table` [JOIN `join_table` ON ...] WHERE fk = pk AND <where>)
     *
     * Returns null (and logs a warning) on any validation failure.
     *
     * @param string $fn           'COUNT' | 'SUM' | 'AVG' | 'MIN' | 'MAX' | 'GROUP_CONCAT'
     * @param string $relatedTable Related table name (from Eloquent model)
     * @param string $fkColumn     FK column name on related table
     * @param string $pkColumn     PK column name on primary table
     * @param string $primaryTable Primary model table name
     * @param array  $aggConfig    Column aggregate config
     * @param string $alias        Column alias (for error logging)
     */
    protected function buildCorrelatedSubquery(
        string $fn,
        string $relatedTable,
        string $fkColumn,
        string $pkColumn,
        string $primaryTable,
        array  $aggConfig,
        string $alias
    ): ?string {
        // Correlation clause: <related_table>.<fk> = <primary_table>.<pk>
        $correlationSql = "`{$relatedTable}`.`{$fkColumn}` = `{$primaryTable}`.`{$pkColumn}`";

        // Build optional JOIN clause (used for GROUP_CONCAT via bridge table)
        $joinSql = '';
        $joinConfig = $aggConfig['join'] ?? null;
        $joinAlias  = null;

        if ($joinConfig !== null) {
            $joinTable   = $joinConfig['table']      ?? null;
            $joinAlias   = $joinConfig['alias']      ?? $joinConfig['table'] ?? null;
            $onLocal     = $joinConfig['on_local']   ?? null;   // column on related table
            $onForeign   = $joinConfig['on_foreign'] ?? null;   // column on join table

            // Validate all join identifiers
            foreach ([$joinTable, $joinAlias, $onLocal, $onForeign] as $ident) {
                if ($ident !== null && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ident)) {
                    try {
                        Log::warning('relation_aggregate: unsafe join identifier — skipped', ['field' => $alias, 'ident' => $ident]);
                    } catch (\Throwable) {}
                    return null;
                }
            }

            if ($joinTable && $onLocal && $onForeign) {
                if ($joinAlias && $joinAlias !== $joinTable) {
                    $joinSql = " JOIN `{$joinTable}` AS `{$joinAlias}` ON `{$relatedTable}`.`{$onLocal}` = `{$joinAlias}`.`{$onForeign}`";
                } else {
                    $joinAlias = $joinTable;
                    $joinSql   = " JOIN `{$joinTable}` ON `{$relatedTable}`.`{$onLocal}` = `{$joinTable}`.`{$onForeign}`";
                }
            }
        }

        // Build WHERE clause from structured conditions
        $whereSql = $this->buildCorrelatedWhereClause($aggConfig['where'] ?? null, $relatedTable, $alias);
        if ($whereSql === false) {
            // Building failed — skip this column
            return null;
        }

        $fullWhere = "WHERE {$correlationSql}";
        if ($whereSql !== '') {
            $fullWhere .= " AND ({$whereSql})";
        }

        // COUNT — no value_field needed
        if ($fn === 'COUNT') {
            return "(SELECT COUNT(*) FROM `{$relatedTable}`{$joinSql} {$fullWhere})";
        }

        // SUM / AVG / MIN / MAX — require value_field on the related table
        if (in_array($fn, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            $valueField = $aggConfig['value_field'] ?? null;
            if (!$valueField || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $valueField)) {
                try {
                    Log::warning("relation_aggregate: {$fn} requires a valid value_field — skipped", ['field' => $alias]);
                } catch (\Throwable) {}
                return null;
            }
            $innerExpr = "{$fn}(`{$relatedTable}`.`{$valueField}`)";
            // SUM/AVG over empty set returns NULL — wrap in COALESCE to return 0 instead.
            // MIN/MAX over empty set have no meaningful default — leave as NULL.
            if ($fn === 'SUM' || $fn === 'AVG') {
                $innerExpr = "COALESCE({$innerExpr}, 0)";
            }
            return "(SELECT {$innerExpr} FROM `{$relatedTable}`{$joinSql} {$fullWhere})";
        }

        // GROUP_CONCAT
        $valueField = $aggConfig['value_field'] ?? null;
        if (!$valueField || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $valueField)) {
            try {
                Log::warning('relation_aggregate: GROUP_CONCAT requires a valid value_field — skipped', ['field' => $alias]);
            } catch (\Throwable) {}
            return null;
        }

        $distinct   = ($aggConfig['distinct'] ?? false) ? 'DISTINCT ' : '';
        $separator  = $aggConfig['separator']  ?? ', ';
        // Escape separator for SQL: single-quote, escape internal quotes
        $safeSeparator = "'" . str_replace("'", "''", $separator) . "'";

        // value_field is either on the join table or the related table
        $valueTableAlias = $joinAlias ?? $relatedTable;
        $valueExpr       = "{$distinct}`{$valueTableAlias}`.`{$valueField}`";

        return "(SELECT GROUP_CONCAT({$valueExpr} SEPARATOR {$safeSeparator}) FROM `{$relatedTable}`{$joinSql} {$fullWhere})";
    }

    /**
     * Build a correlated subquery SQL string for a multi-hop (through-chain) relation_aggregate.
     *
     * Supports SUM/AVG/MIN/MAX/COUNT/GROUP_CONCAT across 2+ hops by resolving each
     * relation step via Eloquent reflection and emitting JOIN clauses.
     *
     * Example — 2-hop (Houses → Sells → Deals):
     *   (SELECT SUM(`d`.`deal_sum`)
     *    FROM `estate_sells` `s`
     *    JOIN `estate_deals` `d` ON `d`.`estate_sell_id` = `s`.`estate_sell_id`
     *    WHERE `s`.`house_id` = `estate_houses`.`house_id`
     *      AND <through_where[0]> AND <leaf where>)
     *
     * Example — 3-hop (Houses → Sells → Deals → Finances):
     *   (SELECT SUM(`f`.`summa`)
     *    FROM `estate_sells` `s`
     *    JOIN `estate_deals` `d` ON `d`.`estate_sell_id` = `s`.`estate_sell_id`
     *    JOIN `finances` `f` ON `f`.`deal_id` = `d`.`deal_id`
     *    WHERE `s`.`house_id` = `estate_houses`.`house_id`
     *      AND <leaf where>)
     *
     * JOIN key resolution:
     *   HasMany/HasOne: JOIN `next_table` ON `next_table`.fk = `current_alias`.pk
     *   BelongsTo:      JOIN `next_table` ON `next_table`.pk = `current_alias`.fk
     *
     * @param string $fn             Aggregate function (uppercase)
     * @param object $firstRelObj    Eloquent relation object for the first hop
     * @param string $firstRelTable  Table name of the first hop model
     * @param Model  $firstRelModel  Model instance of the first hop
     * @param array  $throughChain   Array of relation method names on intermediate models
     * @param array  $aggConfig      Column aggregate config
     * @param string $alias          Column alias (for error logging)
     * @return string|null
     */
    protected function buildThroughSubquery(
        string $fn,
        object $firstRelObj,
        string $firstRelTable,
        Model  $firstRelModel,
        array  $throughChain,
        array  $aggConfig,
        string $alias
    ): ?string {
        $primaryTable = $this->modelInstance->getTable();

        // Resolve the first-hop correlation key.
        [$fk0, $pk0] = $this->resolveRelationJoinKeys($firstRelObj);
        if ($fk0 === null) {
            try {
                Log::warning('relation_aggregate through: unsupported first-hop relation type — skipped', ['field' => $alias]);
            } catch (\Throwable) {}
            return null;
        }

        // Alias for the first-hop table in the subquery
        $firstAlias = 's0';

        // Validate all through-chain relation names
        foreach ($throughChain as $idx => $throughRelName) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $throughRelName)) {
                try {
                    Log::warning('relation_aggregate through: unsafe relation name — skipped', [
                        'field' => $alias, 'through_index' => $idx, 'relation' => $throughRelName,
                    ]);
                } catch (\Throwable) {}
                return null;
            }
        }

        // Walk through the chain building JOIN clauses
        $joinClauses  = [];
        $currentModel = $firstRelModel;
        $currentAlias = $firstAlias;
        $leafAlias    = $firstAlias;
        $leafTable    = $firstRelTable;

        foreach ($throughChain as $idx => $throughRelName) {
            if (!method_exists($currentModel, $throughRelName)) {
                try {
                    Log::warning('relation_aggregate through: relation not found on intermediate model — skipped', [
                        'field'    => $alias,
                        'model'    => get_class($currentModel),
                        'relation' => $throughRelName,
                    ]);
                } catch (\Throwable) {}
                return null;
            }

            try {
                $nextRelObj   = $currentModel->{$throughRelName}();
                $nextModel    = $nextRelObj->getRelated();
                $nextTable    = $nextModel->getTable();
            } catch (\Throwable $e) {
                try {
                    Log::warning('relation_aggregate through: failed to resolve intermediate relation — skipped', [
                        'field' => $alias, 'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
                return null;
            }

            $nextAlias = 's' . ($idx + 1);

            [$joinFk, $joinPk] = $this->resolveRelationJoinKeys($nextRelObj);
            if ($joinFk === null) {
                try {
                    Log::warning('relation_aggregate through: unsupported intermediate relation type — skipped', [
                        'field' => $alias, 'relation' => $throughRelName,
                    ]);
                } catch (\Throwable) {}
                return null;
            }

            // Build per-hop WHERE condition (through_where[idx])
            $throughWhereConfig = $aggConfig['through_where'][$idx] ?? null;
            $hopWhereSql = '';
            if ($throughWhereConfig !== null) {
                // through_where conditions use next table's columns
                $hopWhere = $this->buildCorrelatedWhereClause($throughWhereConfig, $nextTable, $alias);
                if ($hopWhere === false) {
                    return null;
                }
                // Replace bare table references with the alias
                // (buildCorrelatedWhereClause prefixes with relatedTable, we swap to alias)
                $hopWhereSql = str_replace("`{$nextTable}`.", "`{$nextAlias}`.", $hopWhere);
            }

            // Determine JOIN ON clause based on relation direction
            if ($nextRelObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany
                || $nextRelObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
                // FK is on the next table; PK is on the current table
                $onClause = "`{$nextAlias}`.`{$joinFk}` = `{$currentAlias}`.`{$joinPk}`";
            } else {
                // BelongsTo: FK is on current table, PK is on next table
                $onClause = "`{$nextAlias}`.`{$joinPk}` = `{$currentAlias}`.`{$joinFk}`";
            }

            if ($hopWhereSql !== '') {
                $joinClauses[] = "JOIN `{$nextTable}` `{$nextAlias}` ON {$onClause} AND ({$hopWhereSql})";
            } else {
                $joinClauses[] = "JOIN `{$nextTable}` `{$nextAlias}` ON {$onClause}";
            }

            $currentModel = $nextModel;
            $currentAlias = $nextAlias;
            $leafAlias    = $nextAlias;
            $leafTable    = $nextTable;
        }

        // Correlation: first-hop table FK = primary table PK
        // The first relation determines direction:
        if ($firstRelObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany
            || $firstRelObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            $correlationSql = "`{$firstAlias}`.`{$fk0}` = `{$primaryTable}`.`{$pk0}`";
        } else {
            $correlationSql = "`{$firstAlias}`.`{$pk0}` = `{$primaryTable}`.`{$fk0}`";
        }

        // Leaf WHERE conditions (applied to the last hop table)
        $leafWhereSql = $this->buildCorrelatedWhereClause($aggConfig['where'] ?? null, $leafTable, $alias);
        if ($leafWhereSql === false) {
            return null;
        }
        // Replace bare table references with the leaf alias
        if ($leafWhereSql !== '') {
            $leafWhereSql = str_replace("`{$leafTable}`.", "`{$leafAlias}`.", $leafWhereSql);
        }

        $joinsSql  = empty($joinClauses) ? '' : ' ' . implode(' ', $joinClauses);
        $fullWhere = "WHERE {$correlationSql}";
        if ($leafWhereSql !== '') {
            $fullWhere .= " AND ({$leafWhereSql})";
        }

        if ($fn === 'COUNT') {
            return "(SELECT COUNT(*) FROM `{$firstRelTable}` `{$firstAlias}`{$joinsSql} {$fullWhere})";
        }

        if (in_array($fn, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            $valueField = $aggConfig['value_field'] ?? null;
            if (!$valueField || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $valueField)) {
                try {
                    Log::warning("relation_aggregate through: {$fn} requires a valid value_field — skipped", ['field' => $alias]);
                } catch (\Throwable) {}
                return null;
            }
            $innerExpr = "{$fn}(`{$leafAlias}`.`{$valueField}`)";
            // SUM/AVG over empty set returns NULL — wrap in COALESCE to return 0 instead.
            // MIN/MAX over empty set have no meaningful default — leave as NULL.
            if ($fn === 'SUM' || $fn === 'AVG') {
                $innerExpr = "COALESCE({$innerExpr}, 0)";
            }
            return "(SELECT {$innerExpr} FROM `{$firstRelTable}` `{$firstAlias}`{$joinsSql} {$fullWhere})";
        }

        // GROUP_CONCAT through-chain
        $valueField = $aggConfig['value_field'] ?? null;
        if (!$valueField || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $valueField)) {
            try {
                Log::warning('relation_aggregate through: GROUP_CONCAT requires a valid value_field — skipped', ['field' => $alias]);
            } catch (\Throwable) {}
            return null;
        }
        $distinct      = ($aggConfig['distinct'] ?? false) ? 'DISTINCT ' : '';
        $separator     = $aggConfig['separator'] ?? ', ';
        $safeSeparator = "'" . str_replace("'", "''", $separator) . "'";
        $valueExpr     = "{$distinct}`{$leafAlias}`.`{$valueField}`";
        return "(SELECT GROUP_CONCAT({$valueExpr} SEPARATOR {$safeSeparator}) FROM `{$firstRelTable}` `{$firstAlias}`{$joinsSql} {$fullWhere})";
    }

    /**
     * Resolve the FK and PK column names from any supported relation type for JOIN building.
     *
     * For HasMany/HasOne: returns [fkColumn, localKeyColumn]
     *   fkColumn   — FK on the related/child table
     *   localKey   — PK on the parent/owning table
     *
     * For BelongsTo: returns [foreignKey, ownerKey]
     *   foreignKey — FK on the owning (child) model
     *   ownerKey   — PK on the related (parent) model
     *
     * Returns [null, null] for unsupported relation types.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function resolveRelationJoinKeys(object $relationObj): array
    {
        if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany
            || $relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            return [$relationObj->getForeignKeyName(), $relationObj->getLocalKeyName()];
        }

        if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            return [$relationObj->getForeignKeyName(), $relationObj->getOwnerKeyName()];
        }

        return [null, null];
    }

    /**
     * Build the WHERE fragment for a correlated subquery from the `where` config.
     *
     * Supported formats:
     *   Structured (array of conditions): same shape as applyStructuredConditions().
     *     [{ column, operator, value }, ...]
     *   Expression type: { type: 'expression', expr: 'custom_type == "meeting"' }
     *
     * Returns:
     *   string  — SQL fragment (may be empty string '' when no conditions)
     *   false   — building failed (caller should skip the column entirely)
     *
     * @param mixed  $whereConfig  raw 'where' value from aggregate config
     * @param string $relatedTable related table name (for column quoting)
     * @param string $alias        column alias (for error logging)
     * @return string|false
     */
    protected function buildCorrelatedWhereClause(mixed $whereConfig, string $relatedTable, string $alias): string|false
    {
        if ($whereConfig === null || $whereConfig === []) {
            return '';
        }

        // Expression type: translate via ExpressionSqlTranslator
        if (is_array($whereConfig) && ($whereConfig['type'] ?? null) === 'expression') {
            $expr = $whereConfig['expr'] ?? '';
            if ($expr === '') {
                return '';
            }
            $sqlFragment = $this->translateExpressionToSql($expr);
            if ($sqlFragment === null) {
                try {
                    Log::warning('relation_aggregate: expression WHERE not translatable — skipped', [
                        'field' => $alias, 'expr' => $expr,
                    ]);
                } catch (\Throwable) {}
                return false;
            }
            return $sqlFragment;
        }

        // Structured list of conditions (same schema as applyStructuredConditions)
        if (is_array($whereConfig)) {
            // Check if it looks like a list of condition-objects (has numeric keys or 'column' key)
            $isList = isset($whereConfig[0]) || isset($whereConfig['column']);
            $conditions = $isList ? (isset($whereConfig['column']) ? [$whereConfig] : $whereConfig) : [$whereConfig];

            $parts = [];
            foreach ($conditions as $cond) {
                $part = $this->buildSingleCorrelatedCondition($cond, $relatedTable, $alias);
                if ($part === false) {
                    return false;
                }
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            return implode(' AND ', $parts);
        }

        return '';
    }

    /**
     * Build a single structured condition SQL fragment for a correlated subquery.
     *
     * Supported operators (whitelist): same as ALLOWED_OPERATORS.
     * Returns '' for skippable issues; false for fatal errors.
     *
     * @return string|false
     */
    protected function buildSingleCorrelatedCondition(array $cond, string $relatedTable, string $alias): string|false
    {
        // OR/AND nesting — handled recursively
        if (isset($cond['or']) && is_array($cond['or'])) {
            $parts = [];
            foreach ($cond['or'] as $sub) {
                $p = $this->buildSingleCorrelatedCondition($sub, $relatedTable, $alias);
                if ($p === false) {
                    return false;
                }
                if ($p !== '') {
                    $parts[] = $p;
                }
            }
            return empty($parts) ? '' : '(' . implode(' OR ', $parts) . ')';
        }

        if (isset($cond['and']) && is_array($cond['and'])) {
            $parts = [];
            foreach ($cond['and'] as $sub) {
                $p = $this->buildSingleCorrelatedCondition($sub, $relatedTable, $alias);
                if ($p === false) {
                    return false;
                }
                if ($p !== '') {
                    $parts[] = $p;
                }
            }
            return empty($parts) ? '' : '(' . implode(' AND ', $parts) . ')';
        }

        $col = $cond['column'] ?? null;
        $op  = strtolower(trim($cond['operator'] ?? '='));

        if ($col === null) {
            return '';
        }

        // Validate column name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
            try {
                Log::warning('relation_aggregate: unsafe column name in WHERE — skipped', ['field' => $alias, 'col' => $col]);
            } catch (\Throwable) {}
            return false;
        }

        if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
            try {
                Log::warning('relation_aggregate: unsupported operator in WHERE — skipped', ['field' => $alias, 'op' => $op]);
            } catch (\Throwable) {}
            return '';
        }

        $qualifiedCol = "`{$relatedTable}`.`{$col}`";

        // IN / NOT IN with array value
        if ($op === 'in' || $op === 'not in') {
            $values = (array) ($cond['value'] ?? []);
            if (empty($values)) {
                return $op === 'in' ? '0' : '1'; // IN ([]) → always false, NOT IN ([]) → always true
            }
            $quotedValues = $this->quoteValueList($values);
            $sqlOp = $op === 'in' ? 'IN' : 'NOT IN';
            return "{$qualifiedCol} {$sqlOp} ({$quotedValues})";
        }

        // IS NULL / IS NOT NULL
        if ($op === 'is null') {
            return "{$qualifiedCol} IS NULL";
        }
        if ($op === 'is not null') {
            return "{$qualifiedCol} IS NOT NULL";
        }

        // Scalar comparison
        $value = $cond['value'] ?? null;

        // Guard: if a $company_var placeholder was not resolved by ConfigResolver it
        // degrades to [] (empty array). Passing an array to quoteScalarValue() would
        // produce 'Array' (PHP implicit cast), which silently matches 0 rows instead
        // of producing a WHERE 0 / skip.  Treat any array value on a scalar operator
        // as an implicit IN list — consistent with how the 'in' branch already works:
        //   empty array → IN ([]) → always-false literal '0'
        //   single-element array  → IN (val) (same semantics as = val)
        //   multi-element array   → IN (v1, v2, …)
        if (is_array($value)) {
            $values = array_values($value);
            if (empty($values)) {
                return '0'; // IN ([]) → always false; no rows matched
            }
            $quotedValues = $this->quoteValueList($values);
            $sqlOp = match ($op) {
                '!=', '<>' => 'NOT IN',
                default    => 'IN',
            };
            return "{$qualifiedCol} {$sqlOp} ({$quotedValues})";
        }

        $quotedValue = $this->quoteScalarValue($value);

        $sqlOpMap = ['=' => '=', '!=' => '!=', '<>' => '!=', '<' => '<', '>' => '>', '<=' => '<=', '>=' => '>=', 'like' => 'LIKE'];
        $sqlOp = $sqlOpMap[$op] ?? '=';

        return "{$qualifiedCol} {$sqlOp} {$quotedValue}";
    }

    /**
     * Quote a list of scalar values for an IN (...) clause.
     *
     * Uses PDO::quote() when available; falls back to manual escaping.
     * Integer/float values are not quoted (no injection risk).
     */
    protected function quoteValueList(array $values): string
    {
        return implode(', ', array_map(fn($v) => $this->quoteScalarValue($v), $values));
    }

    /**
     * Quote a single scalar value for SQL.
     *
     * Integers and floats are cast to numeric strings (no quotes needed).
     * NULL → SQL NULL. Booleans → 1 / 0. Strings → PDO::quote or escaped string.
     */
    protected function quoteScalarValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return sprintf('%F', $value);
        }

        // String — use PDO::quote when available
        try {
            $pdo = \Illuminate\Support\Facades\DB::connection('macrodata')->getPdo();
            return $pdo->quote((string) $value);
        } catch (\Throwable) {
            // Fallback: doubled-apostrophe escaping
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }
    }

    // -------------------------------------------------------------------------
    // Group-by (master/detail) logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether SQL-level GROUP BY is feasible for the given config.
     *
     * SQL GROUP BY is used when ALL of the following are true:
     *   1. All group_by.fields are direct (no dot-notation) — no JOIN needed.
     *   2. All aggregates.field values are direct (no dot-notation).
     *   3. All aggregate `where` clauses are either:
     *      a) absent (unconditional aggregate), OR
     *      b) type='overdue' (translatable to CASE WHEN date < today AND status IN (...)), OR
     *      c) type='expression' where the expression is translatable by ExpressionSqlTranslator.
     *      Any aggregate with a non-translatable where clause blocks the SQL path.
     *   4. No concat_relation columns in the report config.
     *      concat_relation values are resolved in PHP from eager-loaded collections —
     *      they cannot be expressed as a SQL SELECT expression in a GROUP BY context.
     *
     * Rationale for relation-field exclusion: Eloquent eager-loading builds
     * IN-sub-selects per relation; adding GROUP BY on a joined column requires
     * explicit JOINs that conflict with that pattern and make the query
     * unmaintainable. The PHP-path (chunkById) handles relation-field groups
     * correctly at the cost of higher latency — acceptable until a dedicated
     * SQL join builder is added.
     */
    protected function canUseSqlGroupBy(array $groupByConfig): bool
    {
        // Block SQL path if any column is a concat_relation — these are PHP-resolved.
        // Block SQL path if any column is a relation_aggregate — these are correlated subqueries;
        // GROUP BY queries with correlated subquery aliases in SELECT conflict with paginator counts.
        $columns = $this->config['columns'] ?? [];
        foreach ($columns as $col) {
            if (($col['type'] ?? null) === 'concat_relation') {
                return false;
            }
            if (($col['type'] ?? null) === 'relation_aggregate') {
                return false;
            }
        }

        $fields     = $groupByConfig['fields']     ?? [];
        $aggregates = $groupByConfig['aggregates'] ?? [];

        foreach ($fields as $field) {
            if (str_contains($field, '.')) {
                return false;
            }
        }

        foreach ($aggregates as $aggConfig) {
            $field = $aggConfig['field'] ?? null;
            if ($field !== null && str_contains($field, '.')) {
                return false;
            }

            $whereSpec = $aggConfig['where'] ?? null;
            if (empty($whereSpec)) {
                // No where clause — always SQL-compatible.
                continue;
            }

            $whereType = $whereSpec['type'] ?? null;

            if ($whereType === 'overdue') {
                // CASE WHEN date < today AND status IN (...) — always translatable.
                continue;
            }

            if ($whereType === 'expression') {
                // Check whether ExpressionSqlTranslator can handle this expression.
                // If it cannot, fall back to PHP path for the entire report.
                $expr  = $whereSpec['expr'] ?? '';
                $names = $this->extractExpressionNames($expr);
                $translator = new ExpressionSqlTranslator(null); // No PDO needed for check
                if (!$translator->isTranslatable($expr, $names)) {
                    return false;
                }
                continue;
            }

            // Unknown where type — cannot guarantee SQL safety.
            return false;
        }

        return true;
    }

    /**
     * Fetch groups using SQL-level GROUP BY + paginate().
     *
     * Used only when canUseSqlGroupBy() returns true (direct fields, no relation
     * chains, no conditional aggregates). Each aggregate becomes a selectRaw()
     * clause; aggregate_expressions are evaluated in PHP over the N group rows —
     * this is cheap (N = group count, not row count).
     *
     * Returns [$rows, $meta].
     *
     * @return array{0: array, 1: array}
     */
    protected function getGroupedRowsSql(Builder $query, array $groupByConfig, array $params, int $perPage): array
    {
        $fields               = $groupByConfig['fields']               ?? [];
        $aggregates           = $groupByConfig['aggregates']           ?? [];
        $aggregateExpressions = $groupByConfig['aggregate_expressions'] ?? [];
        $aggregateLabels      = $this->buildAggregateLabels($aggregates, $aggregateExpressions);

        // Build SELECT: group fields + aggregate expressions
        // Always include COUNT(*) as _row_count to populate children_count in the response.
        $selects  = array_map(fn($f) => "`{$f}`", $fields);
        $selects[] = 'COUNT(*) as `_row_count`';
        $aggNames  = [];

        foreach ($aggregates as $name => $aggConfig) {
            $type      = strtoupper($aggConfig['type'] ?? 'COUNT');
            $field     = $aggConfig['field'] ?? null;
            $whereSpec = $aggConfig['where'] ?? null;
            $safeType  = match ($type) {
                'SUM', 'AVG', 'MIN', 'MAX' => $type,
                default                     => 'COUNT',
            };

            if ($whereSpec && ($whereSpec['type'] ?? null) === 'overdue') {
                // CASE WHEN date < today AND status IN (...) THEN field ELSE 0 END
                $dateField   = $whereSpec['date_field']   ?? 'date_to';
                $statusField = $whereSpec['status_field'] ?? 'status';
                $statuses    = (array) ($whereSpec['unpaid_status'] ?? [3]);
                $today       = \Carbon\Carbon::today()->toDateString();
                $inList      = implode(',', array_map('intval', $statuses));
                $valueExpr   = ($safeType !== 'COUNT' && $field !== null)
                    ? "`{$field}`"
                    : '1';
                $selects[] = "SUM(CASE WHEN `{$dateField}` < '{$today}' AND `{$statusField}` IN ({$inList}) THEN {$valueExpr} ELSE 0 END) as `_agg_{$name}`";
            } elseif ($whereSpec && ($whereSpec['type'] ?? null) === 'expression') {
                // CASE WHEN <translated_expr> THEN field ELSE 0 END
                // canUseSqlGroupBy() already verified translatability, so this must succeed.
                $sqlCond   = $this->translateExpressionToSql($whereSpec['expr'] ?? '');
                $valueExpr = ($safeType !== 'COUNT' && $field !== null)
                    ? "`{$field}`"
                    : '1';
                if ($sqlCond !== null) {
                    $selects[] = "SUM(CASE WHEN {$sqlCond} THEN {$valueExpr} ELSE 0 END) as `_agg_{$name}`";
                } else {
                    // Defensive: translation failed despite canUseSqlGroupBy() check — use plain aggregate.
                    $sqlExpr   = ($safeType === 'COUNT' || $field === null) ? 'COUNT(*)' : "{$safeType}(`{$field}`)";
                    $selects[] = "{$sqlExpr} as `_agg_{$name}`";
                }
            } else {
                // Plain unconditional aggregate.
                $sqlExpr = ($safeType === 'COUNT' || $field === null)
                    ? "COUNT(*)"
                    : "{$safeType}(`{$field}`)";
                $selects[] = "{$sqlExpr} as `_agg_{$name}`";
            }
            $aggNames[] = $name;
        }

        $query->reorder()
            ->select([])
            ->selectRaw(implode(', ', $selects))
            ->groupBy($fields);

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($params['page'] ?? 1));

        $rows = [];
        foreach ($paginator->items() as $item) {
            $itemArr = $item instanceof \Illuminate\Database\Eloquent\Model
                ? $item->getAttributes()
                : (array) $item;

            // Re-key aggregates from _agg_<name> back to <name>
            $computedAgg = [];
            foreach ($aggNames as $name) {
                $computedAgg[$name] = $itemArr["_agg_{$name}"] ?? 0;
            }

            // Evaluate aggregate_expressions over computed aggregates (PHP-level, cheap)
            foreach ($aggregateExpressions as $key => $spec) {
                $expr = is_array($spec) ? ($spec['expr'] ?? '') : (string) $spec;
                try {
                    $computedAgg[$key] = $this->expressionLanguage->evaluate($expr, $computedAgg);
                } catch (\Throwable $e) {
                    $computedAgg[$key] = null;
                    try { Log::warning('aggregate_expressions eval failed', ['key' => $key, 'expr' => $expr, 'error' => $e->getMessage()]); } catch (\Throwable) {}
                }
            }

            // Resolve key values
            $keyValues = [];
            foreach ($fields as $field) {
                $keyValues[$field] = $itemArr[$field] ?? null;
            }
            $groupKey = implode('|||', array_map(fn($f) => (string) ($keyValues[$f] ?? ''), $fields));

            $groupMeta = [
                'fields'     => $keyValues,
                'aggregates' => $computedAgg,
            ];
            if (!empty($aggregateLabels)) {
                $groupMeta['aggregate_labels'] = $aggregateLabels;
            }

            $rows[] = [
                'group_key'      => $groupKey,
                'group_meta'     => $groupMeta,
                // _row_count is always injected via COUNT(*) as `_row_count` in $selects above.
                'children_count' => (int) ($itemArr['_row_count'] ?? 0),
                'has_children'   => true,
            ];
        }

        // children_count is populated from the injected COUNT(*) as `_row_count` select.
        $meta = [
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'grouped'   => true,
            'group_by'  => [
                'collapsed_by_default' => $groupByConfig['collapsed_by_default'] ?? false,
                'collapsible'          => $groupByConfig['collapsible']          ?? false,
                'fields'               => $fields,
            ],
        ];

        return [$rows, $meta];
    }

    /**
     * Fetch rows in chunks, group by config fields, paginate groups.
     *
     * Used when canUseSqlGroupBy() returns false (relation-field groups or
     * conditional aggregates). Accumulates groups in-memory via chunkById(500);
     * children[] is NOT included in the response — only children_count and
     * has_children are returned. Actual children are fetched on demand via
     * getGroupRows() / ReportController::groupRows endpoint.
     *
     * Returns [$rows, $meta].
     *
     * chunkById ordering note: chunkById() appends its own ORDER BY on the PK.
     * Any pre-existing ORDER BY clause conflicts and causes row-skipping (~92%
     * data loss observed on report id=8). Fix: strip ordering before chunking,
     * re-apply via usort on the in-memory group list.
     *
     * @return array{0: array, 1: array}
     */
    protected function getGroupedRows(Builder $query, array $groupByConfig, array $params, int $perPage): array
    {
        $fields                = $groupByConfig['fields']                ?? [];
        $aggregates            = $groupByConfig['aggregates']            ?? [];
        $aggregateExpressions  = $groupByConfig['aggregate_expressions'] ?? [];

        // Fast path: pure SQL GROUP BY when all fields are direct (no relations)
        if ($this->canUseSqlGroupBy($groupByConfig)) {
            return $this->getGroupedRowsSql($query, $groupByConfig, $params, $perPage);
        }

        // PHP path: chunkById accumulation for relation-field groups.
        // Strip ORDER BY before chunkById to avoid cursor-pagination conflict.
        $originalOrders = $query->getQuery()->orders ?? [];
        $query->reorder();

        // Accumulate groups incrementally via chunked loading.
        // $groups[$key]['rows'][] = mapped row (never full set in memory at once).
        // $groups[$key]['count'] tracks children count without keeping all rows.
        $groups     = [];
        $totalItems = 0;

        $query->chunkById(500, function ($chunk) use ($fields, &$groups, &$totalItems) {
            foreach ($chunk as $item) {
                $row = $this->mapRow($item, $totalItems, 1, 0);
                $totalItems++;

                $key = implode('|||', array_map(
                    fn($field) => (string) ($row[$field] ?? ''),
                    $fields
                ));

                if (!isset($groups[$key])) {
                    $groups[$key] = ['count' => 0, 'sample' => $row, 'rows' => []];
                }
                $groups[$key]['count']++;
                $groups[$key]['rows'][] = $row;
            }
        });

        // Build aggregate labels lookup
        $aggregateLabels = $this->buildAggregateLabels($aggregates, $aggregateExpressions);

        // Build group rows — children excluded from response, only count/flag
        $groupRows = [];
        foreach ($groups as $key => $groupData) {
            $children    = $groupData['rows'];
            $childCount  = $groupData['count'];
            $keyValues   = array_combine($fields, explode('|||', $key, count($fields)));
            $computedAgg = $this->computeGroupAggregates($children, $aggregates, $aggregateExpressions);

            $groupMeta = [
                'fields'     => $keyValues,
                'aggregates' => $computedAgg,
            ];
            if (!empty($aggregateLabels)) {
                $groupMeta['aggregate_labels'] = $aggregateLabels;
            }

            $groupRows[] = [
                'group_key'      => $key,
                'group_meta'     => $groupMeta,
                'children_count' => $childCount,
                'has_children'   => $childCount > 0,
            ];
        }

        // Re-apply original sort on in-memory group list (usort by first-child representative value)
        if (!empty($originalOrders)) {
            $order     = $originalOrders[0];
            $sortField = $order['column'];
            $sortAsc   = strtolower($order['direction'] ?? 'asc') === 'asc';

            // For the sort representative value we use group_meta.fields when the sort
            // field is a group key field, otherwise fall back to the sample row value.
            usort($groupRows, function ($a, $b) use ($sortField, $sortAsc, $groups) {
                $aVal = $a['group_meta']['fields'][$sortField]
                    ?? ($groups[$a['group_key']]['sample'][$sortField] ?? '');
                $bVal = $b['group_meta']['fields'][$sortField]
                    ?? ($groups[$b['group_key']]['sample'][$sortField] ?? '');

                $cmp = (is_numeric($aVal) && is_numeric($bVal))
                    ? ($aVal <=> $bVal)
                    : strcmp((string) $aVal, (string) $bVal);

                return $sortAsc ? $cmp : -$cmp;
            });
        }

        // Paginate groups
        $page        = (int) ($params['page'] ?? 1);
        $totalGroups = count($groupRows);
        $lastPage    = max(1, (int) ceil($totalGroups / $perPage));
        $page        = max(1, min($page, $lastPage));
        $offset      = ($page - 1) * $perPage;

        $pageRows = array_slice($groupRows, $offset, $perPage);

        $meta = [
            'total'     => $totalGroups,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'grouped'   => true,
            'group_by'  => [
                'collapsed_by_default' => $groupByConfig['collapsed_by_default'] ?? false,
                'collapsible'          => $groupByConfig['collapsible']          ?? false,
                'fields'               => $fields,
            ],
        ];

        return [$pageRows, $meta];
    }

    /**
     * Fetch paginated children rows for a specific group.
     *
     * Called by ReportController::groupRows (GET /reports/{report}/group-rows).
     * Applies all report-level wheres + user filters + sort, then adds WHERE
     * conditions to narrow results to the specified group_key.
     *
     * group_key format: field values joined by '|||', matching group_by.fields order.
     * Empty string in a segment means the field was NULL in the database.
     *
     * @param Report $report
     * @param User   $user
     * @param string $groupKey  Composite group key as returned in getData() rows
     * @param array  $params    Same params shape as getData(): page, per_page, filters, sort
     * @return array            {rows, meta, group_meta}
     */
    public function getGroupRows(Report $report, Company $company, User $user, string $groupKey, array $params): array
    {
        $this->config       = $report->config;
        $this->primaryModel = $this->config['primary_model'] ?? 'EstateDeals';

        $groupByConfig = $this->config['group_by'] ?? null;

        if (!$groupByConfig) {
            return [
                'error' => 'Report does not have group_by configuration',
                'rows'  => [],
                'meta'  => ['total' => 0, 'page' => 1, 'per_page' => 20, 'last_page' => 1],
            ];
        }

        // Connect to MacroData
        try {
            $this->connectionService->connect($company);
        } catch (\Exception $e) {
            return $this->getGroupRowsEmptyResponse($groupByConfig, $params);
        }

        // Resolve per-company variable placeholders before query building.
        $unresolvedVars = [];
        if ($this->configResolver !== null) {
            $this->config = $this->configResolver->resolve($this->config, $company, $unresolvedVars);
        }

        // Re-read groupByConfig after resolution (placeholders inside group_by are now resolved).
        $groupByConfig = $this->config['group_by'];

        $modelClass = $this->getModelClass();
        if (!class_exists($modelClass)) {
            return $this->getGroupRowsEmptyResponse($groupByConfig, $params);
        }

        $this->modelInstance = new $modelClass;

        // Extract relations for columns + group_by fields
        $aggregateFields = array_column($groupByConfig['aggregates'] ?? [], 'field');
        $aggregateFields = array_filter($aggregateFields);
        $this->relations = $this->extractRelations(
            $this->config['columns'] ?? [],
            array_merge($groupByConfig['fields'] ?? [], $aggregateFields)
        );

        // Parse group key into field => value pairs
        $fields      = $groupByConfig['fields'] ?? [];
        $keySegments = explode('|||', $groupKey, max(1, count($fields)));

        // Pad with empty string if segments count < fields count (should not happen but be safe)
        while (count($keySegments) < count($fields)) {
            $keySegments[] = '';
        }

        $keyValues = array_combine($fields, $keySegments);

        // Build base query (global wheres + eager loads + user filters + sort)
        $query = $this->buildQuery($params);

        // Narrow to this group: for each group field, add a WHERE condition.
        // Empty string segment means the original value was NULL (or empty string).
        // We use whereNull OR where('', '') — both cases handled below.
        foreach ($keyValues as $field => $value) {
            if (str_contains($field, '.')) {
                // Relation field: use whereHas on the relation chain
                $parts    = explode('.', $field);
                $column   = array_pop($parts);
                $relation = implode('.', $parts);

                $query->whereHas($relation, function (Builder $q) use ($column, $value) {
                    // Qualify column with the relation's table name to avoid
                    // "Column ambiguous" errors when joined tables share column names.
                    $qualifiedColumn = $q->getModel()->getTable() . '.' . $column;
                    if ($value === '') {
                        $q->where(function (Builder $inner) use ($qualifiedColumn) {
                            $inner->whereNull($qualifiedColumn)->orWhere($qualifiedColumn, '');
                        });
                    } else {
                        $q->where($qualifiedColumn, $value);
                    }
                });
            } else {
                // Direct field — qualify with primary table name to avoid ambiguity
                // when applySort() has added sort JOINs.
                $qField = $this->qualifyPrimaryColumn($field);
                if ($value === '') {
                    $query->where(function (Builder $q) use ($qField) {
                        $q->whereNull($qField)->orWhere($qField, '');
                    });
                } else {
                    $query->where($qField, $value);
                }
            }
        }

        // Paginate children
        $perPage = min(500, max(1, (int) ($params['per_page'] ?? 50)));
        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($params['page'] ?? 1));

        // Map rows
        $visibleColumns = array_values(array_filter(
            $this->config['columns'] ?? [],
            fn($col) => ($col['visible'] ?? true) !== false
        ));
        $visibleFields    = array_column($visibleColumns, 'field');
        $auxiliaryFields  = $this->extraFieldsForColumns($this->config['columns'] ?? []);
        $badgeAuxFields   = $this->extraFieldsForBadges($this->config['columns'] ?? []);
        $allowedFields    = array_unique(array_merge($visibleFields, $auxiliaryFields, $badgeAuxFields));

        $page   = $paginator->currentPage();
        $perPg  = $paginator->perPage();

        $rows = $paginator->getCollection()->map(function ($item, $index) use ($page, $perPg, $allowedFields) {
            $row = $this->mapRow($item, $index, $page, $perPg);
            return $this->filterRowFields($row, $allowedFields);
        })->values()->toArray();

        // Re-compute aggregates for this group using SQL aggregation.
        // This is a single SQL query instead of a full ->get() scan — critical for
        // large groups (14k+ rows in Дебиторка/Акты сверки) to avoid OOM/timeout.
        $aggregates           = $groupByConfig['aggregates']            ?? [];
        $aggregateExpressions = $groupByConfig['aggregate_expressions'] ?? [];
        $aggregateLabels      = $this->buildAggregateLabels($aggregates, $aggregateExpressions);

        $computedAgg = $this->computeGroupAggregatesSql(clone $query, $aggregates, $aggregateExpressions);

        $groupMeta = [
            'fields'     => $keyValues,
            'aggregates' => $computedAgg,
        ];
        if (!empty($aggregateLabels)) {
            $groupMeta['aggregate_labels'] = $aggregateLabels;
        }

        $drillMeta = [
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
        if (!empty($unresolvedVars)) {
            $drillMeta['unresolved_vars'] = array_values($unresolvedVars);
        }

        return [
            'group_key'  => $groupKey,
            'group_meta' => $groupMeta,
            'rows'       => $rows,
            'meta'       => $drillMeta,
        ];
    }

    /**
     * Empty response for getGroupRows when connection fails.
     */
    protected function getGroupRowsEmptyResponse(array $groupByConfig, array $params): array
    {
        $perPage = min(500, max(1, (int) ($params['per_page'] ?? 50)));
        return [
            'group_key'  => $params['group_key'] ?? '',
            'group_meta' => ['fields' => [], 'aggregates' => []],
            'rows'       => [],
            'meta'       => [
                'total'     => 0,
                'page'      => 1,
                'per_page'  => $perPage,
                'last_page' => 1,
            ],
        ];
    }

    /**
     * Build a labels lookup map from aggregates and aggregate_expressions config.
     *
     * Returns an array keyed by aggregate name, value is the label array (or null).
     * Keys without a defined label are omitted.
     *
     * aggregate_expressions supports two forms:
     *   - plain string:  'avg_price_m2' => 'total_price / total_area'
     *   - struct:        'avg_price_m2' => ['expr' => '...', 'label' => ['ru' => '...', 'en' => '...']]
     *
     * @param array $aggregates         group_by.aggregates config
     * @param array $aggregateExpressions group_by.aggregate_expressions config
     * @return array<string, array>      e.g. ['total_area' => ['ru' => 'Площадь', 'en' => 'Area']]
     */
    protected function buildAggregateLabels(array $aggregates, array $aggregateExpressions): array
    {
        $labels = [];

        foreach ($aggregates as $name => $spec) {
            if (!empty($spec['label'])) {
                $labels[$name] = (array) $spec['label'];
            }
        }

        foreach ($aggregateExpressions as $name => $spec) {
            if (is_array($spec) && !empty($spec['label'])) {
                $labels[$name] = (array) $spec['label'];
            }
        }

        return $labels;
    }

    /**
     * Compute aggregates for a group of mapped rows.
     *
     * Aggregate types: count, sum, avg, min, max
     * Optional 'where' filter on children rows before aggregating.
     *
     * Where types:
     *   'overdue'    — same semantics as overdue badge condition
     *   'expression' — 'expr' key with a PHP expression string evaluated via ExpressionLanguage
     *
     * @param array[] $children  Mapped row arrays
     * @param array   $aggregates Aggregates config from group_by
     */
    protected function computeGroupAggregates(array $children, array $aggregates, array $aggregateExpressions = []): array
    {
        $result = [];

        foreach ($aggregates as $name => $aggConfig) {
            $type       = $aggConfig['type']  ?? 'count';
            $field      = $aggConfig['field'] ?? null;
            $whereSpec  = $aggConfig['where'] ?? null;

            // Apply optional where filter on children
            $subset = $whereSpec ? $this->filterChildrenForAggregate($children, $whereSpec) : $children;

            // After mapRow(), dot-notation fields are stored as literal string keys
            // (e.g. "estateDeals.deal_sum"). Use direct array lookup, not data_get(),
            // which would incorrectly treat dots as nested path separators.
            $values = $field !== null
                ? array_map(fn($row) => $row[$field] ?? null, $subset)
                : [];
            $numericValues = array_filter($values, fn($v) => is_numeric($v));

            $result[$name] = match ($type) {
                'sum'   => array_sum($values),
                'count' => count($subset),
                'avg'   => count($subset) > 0
                    ? array_sum($values) / count($subset)
                    : 0,
                'min'   => count($numericValues) > 0 ? min($numericValues) : null,
                'max'   => count($numericValues) > 0 ? max($numericValues) : null,
                default => count($subset),
            };
        }

        // Evaluate aggregate_expressions sequentially — each can reference prior computed keys.
        // Supports both a plain expression string and a struct: ['expr' => '...', 'label' => [...]].
        foreach ($aggregateExpressions as $key => $spec) {
            $expr = is_array($spec) ? ($spec['expr'] ?? '') : (string) $spec;
            try {
                $result[$key] = $this->expressionLanguage->evaluate($expr, $result);
            } catch (\Throwable $e) {
                $result[$key] = null;
                try {
                    Log::warning('aggregate_expressions eval failed', [
                        'key'   => $key,
                        'expr'  => $expr,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Log facade unavailable outside Laravel container (e.g. unit tests)
                }
            }
        }

        return $result;
    }

    /**
     * Compute group aggregates via a single SQL query instead of loading all rows.
     *
     * Strategy per aggregate type:
     *   - count/sum/avg/min/max without `where` clause → direct SQL function (SUM, COUNT, etc.)
     *   - conditional aggregates (`where.type='overdue'`) → CASE WHEN ... THEN ... ELSE 0 END
     *   - `where.type='expression'` → PHP fallback via ->get() (Symfony ExpressionLanguage
     *     cannot be translated to SQL safely — only affects expression-where aggregates,
     *     not the full row set; documented limitation)
     *   - aggregate_expressions → computed in PHP over the N already-aggregated scalar
     *     values (cheap: N = number of aggregate keys, not number of rows)
     *
     * The `$query` must already be narrowed to the target group (via whereHas / where clauses).
     *
     * @param Builder $query        Pre-narrowed query (no pagination)
     * @param array   $aggregates   group_by.aggregates config
     * @param array   $aggregateExpressions group_by.aggregate_expressions config
     * @return array<string, mixed>
     */
    protected function computeGroupAggregatesSql(Builder $query, array $aggregates, array $aggregateExpressions = []): array
    {
        if (empty($aggregates)) {
            // No configured aggregates — evaluate expressions (likely empty too) and return.
            $result = [];
            foreach ($aggregateExpressions as $key => $spec) {
                $expr = is_array($spec) ? ($spec['expr'] ?? '') : (string) $spec;
                try {
                    $result[$key] = $this->expressionLanguage->evaluate($expr, $result);
                } catch (\Throwable) {
                    $result[$key] = null;
                }
            }
            return $result;
        }

        // Separate aggregates into SQL-translatable and PHP-fallback groups.
        //
        // SQL-translatable:
        //   - No where clause (plain SUM/COUNT/AVG/MIN/MAX)
        //   - where.type='overdue'  → CASE WHEN date < today AND status IN (...)
        //   - where.type='expression' where ExpressionSqlTranslator succeeds
        //
        // PHP-fallback:
        //   - where.type='expression' where translator fails (complex / unsupported syntax)
        //   - Any unknown where.type
        $sqlAggregates  = [];
        $phpAggregates  = [];

        foreach ($aggregates as $name => $aggConfig) {
            $whereSpec = $aggConfig['where'] ?? null;
            if (!$whereSpec) {
                $sqlAggregates[$name] = $aggConfig;
                continue;
            }

            $whereType = $whereSpec['type'] ?? null;

            if ($whereType === 'overdue') {
                $sqlAggregates[$name] = $aggConfig;
                continue;
            }

            if ($whereType === 'expression') {
                $expr   = $whereSpec['expr'] ?? '';
                $sqlFrg = $this->translateExpressionToSql($expr);
                if ($sqlFrg !== null) {
                    // Store translated fragment in config for the SQL pass below.
                    $aggConfig['_translated_sql'] = $sqlFrg;
                    $sqlAggregates[$name] = $aggConfig;
                } else {
                    // Cannot translate — load rows in PHP.
                    $phpAggregates[$name] = $aggConfig;
                }
                continue;
            }

            // Unknown where type — PHP fallback.
            $phpAggregates[$name] = $aggConfig;
        }

        $result = [];

        // ---- SQL pass: single aggregation query ----
        $selectParts = ['COUNT(*) as `_row_count`'];
        foreach ($sqlAggregates as $name => $aggConfig) {
            $type      = strtoupper($aggConfig['type'] ?? 'COUNT');
            $field     = $aggConfig['field'] ?? null;
            $whereSpec = $aggConfig['where'] ?? null;

            $safeType = match ($type) {
                'SUM', 'AVG', 'MIN', 'MAX' => $type,
                default                     => 'COUNT',
            };

            $valueExpr = ($safeType !== 'COUNT' && $field !== null)
                ? "`{$field}`"
                : '1';

            if ($whereSpec && ($whereSpec['type'] ?? null) === 'overdue') {
                // CASE WHEN date < today AND status IN (...) THEN field ELSE 0 END
                $dateField   = $whereSpec['date_field']   ?? 'date_to';
                $statusField = $whereSpec['status_field'] ?? 'status';
                $statuses    = (array) ($whereSpec['unpaid_status'] ?? [3]);
                $today       = Carbon::today()->toDateString();
                $inList      = implode(',', array_map('intval', $statuses));
                $selectParts[] = "SUM(CASE WHEN `{$dateField}` < '{$today}' AND `{$statusField}` IN ({$inList}) THEN {$valueExpr} ELSE 0 END) as `_agg_{$name}`";
            } elseif ($whereSpec && ($whereSpec['type'] ?? null) === 'expression' && isset($aggConfig['_translated_sql'])) {
                // CASE WHEN <translated_expression> THEN field ELSE 0 END
                $sqlFrg = $aggConfig['_translated_sql'];
                $selectParts[] = "SUM(CASE WHEN {$sqlFrg} THEN {$valueExpr} ELSE 0 END) as `_agg_{$name}`";
            } else {
                // Plain unconditional aggregate
                $sqlExpr = ($safeType === 'COUNT' || $field === null)
                    ? 'COUNT(*)'
                    : "{$safeType}(`{$field}`)";
                $selectParts[] = "{$sqlExpr} as `_agg_{$name}`";
            }
        }

        $aggRow = $query->reorder()->selectRaw(implode(', ', $selectParts))->first();
        $aggArr = $aggRow instanceof \Illuminate\Database\Eloquent\Model
            ? $aggRow->getAttributes()
            : (array) $aggRow;

        foreach ($sqlAggregates as $name => $aggConfig) {
            $result[$name] = $aggArr["_agg_{$name}"] ?? 0;
        }

        // ---- PHP fallback pass (non-translatable expression-where only) ----
        // Loads all rows. This path fires only for expressions that ExpressionSqlTranslator
        // cannot handle (e.g. method calls, regex). Simple == / != / && expressions are now
        // handled in the SQL pass above, so this path should be rare.
        if (!empty($phpAggregates)) {
            $allItems = (clone $query)->reorder()->get();
            $allRows  = $allItems->map(fn($item) => $this->mapRow($item, 0, 1, 0))->toArray();
            $phpResult = $this->computeGroupAggregates($allRows, $phpAggregates, []);
            $result = array_merge($result, $phpResult);
        }

        // ---- aggregate_expressions: cheap PHP eval over N scalar values ----
        foreach ($aggregateExpressions as $key => $spec) {
            $expr = is_array($spec) ? ($spec['expr'] ?? '') : (string) $spec;
            try {
                $result[$key] = $this->expressionLanguage->evaluate($expr, $result);
            } catch (\Throwable $e) {
                $result[$key] = null;
                try { Log::warning('aggregate_expressions eval failed', ['key' => $key, 'expr' => $expr, 'error' => $e->getMessage()]); } catch (\Throwable) {}
            }
        }

        return $result;
    }

    /**
     * Filter children rows for aggregate computation based on a where spec.
     *
     * Supported where types:
     *   'overdue'    — row has date_field < today AND status_field IN unpaid_status
     *   'expression' — Symfony ExpressionLanguage evaluates 'expr' against row variables
     *
     * @param array[] $children
     * @param array   $whereSpec
     * @return array[]
     */
    protected function filterChildrenForAggregate(array $children, array $whereSpec): array
    {
        $type = $whereSpec['type'] ?? null;

        return match ($type) {
            'overdue' => array_filter($children, function (array $row) use ($whereSpec) {
                $dateField      = $whereSpec['date_field']    ?? 'date_to';
                $statusField    = $whereSpec['status_field']  ?? 'status';
                $unpaidStatuses = $whereSpec['unpaid_status'] ?? [3];

                $dateValue = $row[$dateField] ?? null;
                if ($dateValue === null) {
                    return false;
                }
                try {
                    $date = $dateValue instanceof Carbon ? $dateValue : Carbon::parse($dateValue);
                } catch (\Throwable) {
                    return false;
                }
                if (!$date->lt(Carbon::today())) {
                    return false;
                }
                return in_array($row[$statusField] ?? null, (array) $unpaidStatuses, false);
            }),

            'expression' => array_filter($children, function (array $row) use ($whereSpec) {
                $expr = $whereSpec['expr'] ?? 'false';
                try {
                    $vars = array_map(
                        fn($v) => is_numeric($v) ? (float) $v : $v,
                        $row
                    );
                    return (bool) $this->expressionLanguage->evaluate($expr, $vars);
                } catch (\Throwable) {
                    return false;
                }
            }),

            default => $children,
        };
    }

    // -------------------------------------------------------------------------
    // Expression → SQL translation helpers
    // -------------------------------------------------------------------------

    /**
     * Extract simple identifier names from an expression string.
     *
     * Used to supply the `$names` argument to ExpressionLanguage::parse().
     * Extracts word tokens that are not language keywords; dots are rejected
     * (dot-notation field paths are not supported in expression-where).
     *
     * @param  string $expression
     * @return string[]
     */
    protected function extractExpressionNames(string $expression): array
    {
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $expression, $matches);
        $reserved = ['null', 'true', 'false', 'and', 'or', 'not', 'in', 'matches', 'contains', 'starts', 'ends', 'with'];
        $names = [];
        foreach ($matches[1] as $identifier) {
            if (!in_array(strtolower($identifier), $reserved, true)) {
                $names[] = $identifier;
            }
        }
        return array_unique($names);
    }

    /**
     * Attempt to translate an ExpressionLanguage expression to a SQL boolean fragment.
     *
     * Returns the SQL string on success, or null if the expression is not
     * translatable (uses unsupported constructs). Null signals the caller to
     * fall back to PHP-path evaluation.
     *
     * The translator is given the live MacroData PDO connection for safe string
     * quoting. It is (re-)created lazily here because connect() has not been
     * called yet at construction time.
     *
     * @param  string $expression  ExpressionLanguage expression
     * @return string|null         SQL fragment, or null on failure
     */
    protected function translateExpressionToSql(string $expression): ?string
    {
        $names = $this->extractExpressionNames($expression);

        // Provide live PDO for string quoting when the connection is available.
        try {
            $pdo = \Illuminate\Support\Facades\DB::connection('macrodata')->getPdo();
        } catch (\Throwable) {
            $pdo = null;
        }
        $translator = new ExpressionSqlTranslator($pdo);

        try {
            return $translator->translate($expression, $names);
        } catch (TranslationException) {
            return null;
        }
    }

    /**
     * Build available filters for frontend based on columns with options from database.
     *
     * Optimization: relation-field columns all share a single ->limit(1000)->get() call.
     * Without this, each relation-field filter would clone the query and do its own
     * ->limit(1000)->get() with a separate eager-load (3 relation fields × ~2s = ~6s).
     * A shared batch load collapses this to one query with merged with() clauses.
     */
    protected function buildAvailableFilters(Builder $baseQuery): array
    {
        $available = [];
        $columns   = $this->config['columns'] ?? [];

        // Separate filterable columns into direct-field, relation-field, and async groups.
        $directColumns   = [];   // field has no dot, normal sync options
        $relationColumns = [];   // field has dot — require eager-load to resolve
        $asyncColumns    = [];   // filter_type='async_select' — no options, just metadata

        foreach ($columns as $column) {
            $field      = $column['field'] ?? null;
            $type       = $column['type'] ?? 'text';
            $filterable = $column['filterable'] ?? true;

            if (!$field || !$filterable || isset($column['expression']) || isset($column['renderer'])) {
                continue;
            }

            // window_aggregate columns are computed by MySQL — their alias does not exist
            // as a real column in the primary table, so filter options cannot be fetched.
            if ($type === 'window_aggregate') {
                continue;
            }

            // concat_relation columns aggregate a hasMany collection in PHP — no real SQL column.
            // Filtering is handled via extra_filters (has_any_pivot operation), not via this column.
            if ($type === 'concat_relation') {
                continue;
            }

            // relation_aggregate columns are computed by correlated subqueries — the alias is not
            // a real column in the primary table, so SELECT-based min/max cannot be fetched.
            // However, when the column is explicitly marked filterable (the default), we emit a
            // number_range filter without options (no min/max). The WHERE is applied in
            // applyFilters() via a repeated correlated subquery on the primary table.
            if ($type === 'relation_aggregate') {
                $filter = ['type' => 'number_range'];
                if (isset($column['header'])) {
                    $filter['label'] = $column['header'];
                }
                // No options/min/max — frontend renders open-ended numeric range inputs.
                $available[$field] = $filter;
                continue;
            }

            // payment_schedule columns are composite objects — not filterable from the
            // filters_available list. Filtering happens per-cell on the frontend.
            if ($type === 'payment_schedule') {
                continue;
            }

            // custom_attribute columns are correlated subquery aliases — no real column
            // exists in the primary table, so filter options cannot be fetched.
            // Filtering on EAV values is a future extension.
            if ($type === 'custom_attribute') {
                continue;
            }

            // async_select columns: emit metadata only, no options pre-fetched.
            if (($column['filter_type'] ?? null) === 'async_select') {
                $asyncColumns[] = $column;
                continue;
            }

            if (str_contains($field, '.')) {
                $relationColumns[] = $column;
            } else {
                $directColumns[] = $column;
            }
        }

        // ---- Direct fields: one query per filter type (fast, indexed) ----
        foreach ($directColumns as $column) {
            $field      = $column['field'];
            $type       = $column['type'] ?? 'text';
            $filterType = match ($type) {
                'date', 'datetime'   => 'date_range',
                'currency', 'number' => 'number_range',
                'badge', 'status'    => 'multiselect',
                default              => 'select',
            };

            $filter = ['type' => $filterType];
            if (isset($column['header'])) {
                $filter['label'] = $column['header'];
            }

            $options = $this->getFilterOptions($baseQuery->clone(), $field, $filterType, $column);
            if ($options !== null) {
                $filter['options'] = $options;
            }

            $default = $this->resolveFilterDefault($column['filter_default'] ?? null);
            if ($default !== null) {
                $filter['default'] = $default;
            }

            $available[$field] = $filter;
        }

        // ---- Relation fields: single shared ->get() with merged with() clauses ----
        if (!empty($relationColumns)) {
            $available = array_merge(
                $available,
                $this->buildRelationFilterOptions($baseQuery, $relationColumns)
            );
        }

        // ---- Async-select fields: emit metadata only (no options pre-fetched) ----
        foreach ($asyncColumns as $column) {
            $field  = $column['field'];
            $filter = [
                'type'            => 'async_select',
                'async'           => true,
                'search_endpoint' => '/api/reports/' . ($this->config['_report_id'] ?? '') . '/filter-options/' . $field,
            ];
            if (isset($column['header'])) {
                $filter['label'] = $column['header'];
            }
            $available[$field] = $filter;
        }

        // ---- extra_filters: standalone filters not tied to a column ----
        foreach ($this->config['extra_filters'] ?? [] as $def) {
            $key       = $def['key']       ?? null;
            $operation = $def['operation'] ?? null;

            if (!$key || !$operation) {
                continue;
            }

            if ($operation === 'has_any_pivot') {
                // Emit async_select metadata: options are fetched via options_source
                $optionsSource = $def['options_source'] ?? null;
                $filter = [
                    'type'      => 'async_select',
                    'async'     => true,
                    'multiple'  => true,
                    'operation' => $operation,
                    'search_endpoint' => '/api/reports/' . ($this->config['_report_id'] ?? '') . '/filter-options/' . $key,
                ];
                if (isset($def['label'])) {
                    $filter['label'] = $def['label'];
                }
                $available[$key] = $filter;
            }
        }

        return $available;
    }

    /**
     * Build filter options for all relation-field columns in one shared query.
     *
     * All required relation chains are collected and merged into a single with() call.
     * This means one ->limit(1000)->get() instead of N separate ones, avoiding
     * repeated eager-load round-trips for the same base data.
     *
     * @param  Builder $baseQuery
     * @param  array[] $relationColumns  Column configs that have dot-notation field paths
     * @return array<string, array>      Map of field → filter definition
     */
    protected function buildRelationFilterOptions(Builder $baseQuery, array $relationColumns): array
    {
        // Collect all relation chains needed
        $allRelations = [];
        foreach ($relationColumns as $column) {
            $parts = explode('.', $column['field']);
            array_pop($parts); // remove field name, keep relation chain
            if (!empty($parts)) {
                $chain = implode('.', $parts);
                $allRelations[$chain] = true;
            }
        }

        // Single batch load — shared for all relation filter columns.
        // reorder() strips ORDER BY from the clone: applySort() may have injected a
        // relation_aggregate subquery alias that does not exist in this plain SELECT *,
        // causing "Unknown column '...' in 'order clause'" on MySQL.
        $items = $baseQuery->clone()
            ->reorder()
            ->with(array_keys($allRelations))
            ->limit(1000)
            ->get();

        $result = [];
        foreach ($relationColumns as $column) {
            $field      = $column['field'];
            $type       = $column['type'] ?? 'text';
            $filterType = match ($type) {
                'date', 'datetime'   => 'date_range',
                'currency', 'number' => 'number_range',
                'badge', 'status'    => 'multiselect',
                default              => 'select',
            };

            $values = $items->map(fn($item) => data_get($item, $field))
                ->filter()
                ->unique();

            $options = match ($filterType) {
                'date_range' => [
                    'min' => $values->min(),
                    'max' => $values->max(),
                ],
                'number_range' => [
                    'min' => (float) $values->min(),
                    'max' => (float) $values->max(),
                ],
                'select', 'multiselect' => $values->take(100)->map(fn($v) => [
                    'value' => $v,
                    'label' => $this->resolveOptionsFilterLabel($v, $column),
                ])->values()->toArray(),
                default => null,
            };

            $filter = ['type' => $filterType];
            if (isset($column['header'])) {
                $filter['label'] = $column['header'];
            }
            if ($options !== null) {
                $filter['options'] = $options;
            }

            $default = $this->resolveFilterDefault($column['filter_default'] ?? null);
            if ($default !== null) {
                $filter['default'] = $default;
            }

            $result[$field] = $filter;
        }

        return $result;
    }

    /**
     * Resolve a column's filter_default config into a concrete value.
     *
     * Placeholders (strings matching /^\{[^}]+\}$/) are expanded via the same
     * placeholder table used by resolveDynamicValue(), but are serialised to
     * Y-m-d (dates) or Y-m-d H:i:s (datetimes) strings so the frontend can
     * use them directly without Carbon awareness.
     *
     * Supported shapes:
     *   date_range / number_range : ['from' => ..., 'to' => ...]
     *   select / multiselect       : ['value' => scalar|array]
     *   text                       : ['value' => string]
     *
     * If the supplied $rawDefault is null or not an array, null is returned and
     * no `default` key is added to the filter metadata.
     */
    protected function resolveFilterDefault(mixed $rawDefault): ?array
    {
        if (!is_array($rawDefault) || empty($rawDefault)) {
            return null;
        }

        $resolved = [];
        foreach ($rawDefault as $key => $val) {
            if (is_array($val)) {
                // value key contains an array (multiselect default: [1, 2])
                $resolved[$key] = array_map(fn($v) => $this->resolveFilterDefaultScalar($v), $val);
            } else {
                $resolved[$key] = $this->resolveFilterDefaultScalar($val);
            }
        }

        return $resolved;
    }

    /**
     * Resolve a single scalar value inside a filter_default array.
     *
     * - Placeholder strings like {end_of_month} → resolved via resolveDynamicValue(),
     *   then Carbon instances are formatted to Y-m-d.
     * - Everything else is returned as-is (int, float, bool, plain string).
     */
    protected function resolveFilterDefaultScalar(mixed $val): mixed
    {
        if ($val === null) {
            return null;
        }

        $resolved = $this->resolveDynamicValue($val);

        // resolveDynamicValue returns Carbon for recognised placeholders.
        // Serialise to date string that the frontend datepicker understands.
        if ($resolved instanceof \Carbon\Carbon) {
            return $resolved->format('Y-m-d');
        }

        return $resolved;
    }

    /**
     * Get filter options from database based on filter type
     */
    protected function getFilterOptions(Builder $query, string $field, string $filterType, array $columnConfig = []): mixed
    {
        // Handle relation fields (with dots)
        if (str_contains($field, '.')) {
            return $this->getRelationFilterOptions($query, $field, $filterType, $columnConfig);
        }

        // Handle direct fields
        return match ($filterType) {
            'date_range' => $this->getDateRangeOptions($query, $field),
            'number_range' => $this->getNumberRangeOptions($query, $field),
            'select', 'multiselect' => $this->getSelectOptions($query, $field, columnConfig: $columnConfig),
            default => null,
        };
    }

    /**
     * Get options for relation field using Eloquent
     */
    protected function getRelationFilterOptions(Builder $query, string $field, string $filterType, array $columnConfig = []): mixed
    {
        $parts = explode('.', $field);
        $targetField = array_pop($parts);
        $relationChain = implode('.', $parts);

        // Eager load the relation chain and collect values from results
        $query->with($relationChain);

        // Get limited results to extract options.
        // reorder() strips inherited ORDER BY (e.g. relation_aggregate alias injected by
        // applySort) that is absent in this plain SELECT *, which would cause MySQL error.
        $items = $query->reorder()->limit(1000)->get();

        // Extract values using dotted path
        $values = $items->map(function ($item) use ($field) {
            return data_get($item, $field);
        })->filter()->unique();

        return match ($filterType) {
            'date_range' => [
                'min' => $values->min(),
                'max' => $values->max(),
            ],
            'number_range' => [
                'min' => (float) $values->min(),
                'max' => (float) $values->max(),
            ],
            'select', 'multiselect' => $values->take(100)->map(fn($v) => [
                'value' => $v,
                'label' => $this->resolveOptionsFilterLabel($v, $columnConfig),
            ])->values()->toArray(),
            default => null,
        };
    }

    /**
     * Get date range options using Eloquent
     */
    protected function getDateRangeOptions(Builder $query, string $field): ?array
    {
        $min = $query->clone()->min($field);
        $max = $query->clone()->max($field);

        if ($min === null) {
            return null;
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Get number range options using Eloquent
     */
    protected function getNumberRangeOptions(Builder $query, string $field): ?array
    {
        $min = $query->clone()->min($field);
        $max = $query->clone()->max($field);

        if ($min === null) {
            return null;
        }

        return [
            'min' => (float) $min,
            'max' => (float) $max,
        ];
    }

    /**
     * Get select options (unique values) using Eloquent
     */
    protected function getSelectOptions(Builder $query, string $field, int $limit = 100, array $columnConfig = []): ?array
    {
        $values = $query->clone()
            ->reorder()
            ->select($field)
            ->distinct()
            ->limit($limit)
            ->pluck($field)
            ->filter()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->map(fn($v) => [
            'value' => $v,
            'label' => $this->resolveOptionsFilterLabel($v, $columnConfig),
        ])->toArray();
    }

    /**
     * Apply an `options` mapping from a column config to a raw value.
     *
     * If the column has no `options` key, or the value is null, or the value has
     * no entry in the map, the raw value is returned unchanged (graceful fallback).
     *
     * Locale resolution order:
     *   1. app()->getLocale() — the current Laravel locale (e.g. 'ru', 'en').
     *   2. If the locale key is absent in the entry, fall back to 'en'.
     *   3. If 'en' is also absent, use the first value in the entry array.
     *
     * Flat string entries (not {ru, en} objects) are returned as-is regardless of locale.
     *
     * @param  mixed $value       Raw value from the database (e.g. 'flat', 'comm').
     * @param  array $columnConfig Column config array that may contain 'options'.
     * @return mixed              Localised label string, or the original $value if not found.
     */
    protected function applyOptionsLabel(mixed $value, array $columnConfig): mixed
    {
        if ($value === null || !isset($columnConfig['options']) || !is_array($columnConfig['options'])) {
            return $value;
        }

        $map = $columnConfig['options'];

        // Cast to string for map lookup (DB values are typically strings/ints).
        $key = (string) $value;

        if (!array_key_exists($key, $map)) {
            return $value; // unknown value — return raw
        }

        $entry = $map[$key];

        // Flat scalar — return as-is
        if (!is_array($entry)) {
            return $entry;
        }

        // Localised object {ru: '...', en: '...'}
        $locale = app()->getLocale();

        if (isset($entry[$locale])) {
            return $entry[$locale];
        }

        // Fallback: 'en', then first value
        if (isset($entry['en'])) {
            return $entry['en'];
        }

        return reset($entry) ?: $value;
    }

    /**
     * Resolve the `label` value for a filter option entry.
     *
     * Used exclusively when building filter options for `filters_available`.
     * Unlike `applyOptionsLabel()`, this method does NOT resolve to a single
     * locale string — instead it returns the full {ru, en} object so that the
     * frontend can pick the correct locale without a round-trip.
     *
     * Resolution rules:
     *   - Column has an `options` map AND the value is a known key:
     *       • entry is a localised array ({ru, en}) → return the array as-is.
     *       • entry is a flat scalar              → return it as-is (back-compat).
     *   - Value is not in the map, or no map at all → return the raw value (string).
     *
     * @param  mixed $value       Raw value from the database (e.g. 'flat').
     * @param  array $columnConfig Column config array that may contain 'options'.
     * @return mixed              {ru: string, en: string} object, flat scalar, or raw value.
     */
    protected function resolveOptionsFilterLabel(mixed $value, array $columnConfig): mixed
    {
        if ($value === null) {
            return $value;
        }

        $map = $columnConfig['options'] ?? null;

        if (!is_array($map)) {
            // No options map — label equals the raw value
            return $value;
        }

        $key = (string) $value;

        if (!array_key_exists($key, $map)) {
            // Unknown value — fall back to raw
            return $value;
        }

        // Return entry as-is: either {ru, en} object or flat scalar.
        return $map[$key];
    }

    /**
     * Get actually applied filters from user params
     */
    protected function getAppliedFilters(array $params): array
    {
        return $params['filters'] ?? [];
    }

    /**
     * Merge footer-declared aggregates from column definitions into the totals config.
     *
     * Each column may declare a `footer` key:
     *   footer: {agg: 'count'}  — count of all filtered rows (COUNT(*) on the whole dataset)
     *   footer: {agg: 'sum'}    — SUM of the column's field across all filtered rows
     *
     * These are merged into the totals config map before buildTotals() processes it.
     * Existing explicit totals entries are NOT overwritten — footer is additive only.
     *
     * The `count` aggregate is special: it counts all rows in the filtered dataset,
     * not a specific field. The result is stored under the column's field name so the
     * frontend can render it in the footer cell of that column.
     *
     * @param mixed $totalsConfig  Existing totals config from report.config.totals
     * @param array $columns       Column definitions from report.config.columns
     * @return array               Merged totals config (associative: field => agg_type)
     */
    protected function mergeFooterIntoTotals(mixed $totalsConfig, array $columns): array
    {
        // Normalise existing totals config to associative [field => agg_type]
        $merged = [];
        foreach ((array) $totalsConfig as $key => $val) {
            if (is_int($key)) {
                // Simple array entry: ['deal_sum'] → deal_sum => 'sum'
                $merged[(string) $val] = 'sum';
            } else {
                $merged[$key] = $val;
            }
        }

        foreach ($columns as $column) {
            $field  = $column['field'] ?? null;
            $footer = $column['footer'] ?? null;

            if (!$field || !is_array($footer)) {
                continue;
            }

            $agg = strtolower(trim($footer['agg'] ?? ''));
            if (!in_array($agg, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                continue;
            }

            // Only add if not already declared in the explicit totals config.
            if (!array_key_exists($field, $merged)) {
                $merged[$field] = $agg;
            }
        }

        return $merged;
    }

    /**
     * Calculate totals for numeric fields with applied filters.
     *
     * Supports two configuration sources:
     *
     * 1. config.totals — top-level array of fields to aggregate (existing mechanism).
     *    Can be a flat list ['field1', 'field2'] (implies sum) or an associative map
     *    ['field1' => 'sum', 'field2' => 'count'].
     *
     * 2. columns[].footer — per-column footer aggregate declaration (new in Фича B).
     *    footer: {agg: 'count'|'sum'|'avg'|'min'|'max'}
     *    These are merged into totals automatically before processing. Explicit
     *    totals entries take priority over footer declarations.
     *
     * Special: agg='count' on any field type means COUNT(*) of all filtered rows.
     * The count value is stored under the column's field name so the frontend renders
     * it in the footer cell of that specific column.
     */
    protected function buildTotals(Builder $query): array
    {
        $columns = $this->config['columns'] ?? [];

        // Merge footer declarations from columns into totals config.
        // This makes footer: {agg: 'count'} work even if config.totals is absent.
        $rawTotalsConfig = $this->config['totals'] ?? [];
        $totalsConfig    = $this->mergeFooterIntoTotals($rawTotalsConfig, $columns);

        if (empty($totalsConfig)) {
            return [];
        }

        $totals = [];

        // Build column map for expression resolution
        $columnMap = [];
        foreach ($columns as $column) {
            $field = $column['field'] ?? null;
            if ($field) {
                $columnMap[$field] = $column;
            }
        }

        // Determine which totals fields are expose-aliases of payment_schedule columns.
        // These cannot be aggregated via SQL (the field does not exist on the primary table);
        // instead we SUM them in PHP over a dedicated batch-SELECT (see buildExposeTotals()).
        $exposeFields = $this->collectExposeFields(); // [targetKey => psColumnConfig, ...]

        // Pre-compute expose totals (one batch-SELECT for all expose fields) only when needed.
        $exposeTotals = null;

        // First pass: calculate all direct field aggregates
        $fieldAggregates = [];
        foreach ($totalsConfig as $field => $aggregation) {
            if (is_int($field)) {
                // Simple array: ['deal_sum', 'finances_income']
                $field = $aggregation;
                $aggregation = 'sum';
            }

            // Skip expression fields for now
            if (isset($columnMap[$field]) && isset($columnMap[$field]['expression'])) {
                continue;
            }

            // Skip window_aggregate fields — their alias is not a real stored column;
            // aggregating the alias via SUM() in a plain query would fail or return NULL.
            if (isset($columnMap[$field]) && ($columnMap[$field]['type'] ?? null) === 'window_aggregate') {
                continue;
            }

            // Skip payment_schedule fields — composite objects, not aggregatable.
            if (isset($columnMap[$field]) && ($columnMap[$field]['type'] ?? null) === 'payment_schedule') {
                continue;
            }

            // Skip relation_aggregate fields at this stage — handled via buildRelationAggregateTotals() below.
            if (isset($columnMap[$field]) && ($columnMap[$field]['type'] ?? null) === 'relation_aggregate') {
                continue;
            }

            // Skip expose-alias fields — they are computed from finances, not from the primary table.
            // They are handled below via buildExposeTotals().
            if (isset($exposeFields[$field])) {
                continue;
            }

            // Check if field has a relation chain
            if (str_contains($field, '.')) {
                $aggregate = $this->calculateRelationAggregate($query->clone(), $field, $aggregation);
            } else {
                $aggregate = $this->calculateDirectAggregate($query->clone(), $field, $aggregation);
            }

            if ($aggregate !== null) {
                $fieldAggregates[$field] = $aggregate;
            }
        }

        // Compute relation_aggregate totals — one SELECT per RA column in the totals config.
        // Results are merged into $fieldAggregates so expression columns can reference them.
        $raTotals = $this->buildRelationAggregateTotals($query->clone(), $totalsConfig, $columnMap);
        foreach ($raTotals as $raField => $raVal) {
            $fieldAggregates[$raField] = $raVal;
        }

        // Compute expose totals lazily — one batch-SELECT covers all expose keys.
        $exposeTotalsNeeded = array_intersect_key($exposeFields, array_flip(array_map(
            fn($k, $v) => is_int($k) ? $v : $k,
            array_keys((array) $totalsConfig),
            (array) $totalsConfig,
        )));
        if (!empty($exposeTotalsNeeded)) {
            $exposeTotals = $this->buildExposeTotals($query->clone(), $exposeFields);
        }

        // Merge expose totals into fieldAggregates so expression fields can reference them.
        if ($exposeTotals !== null) {
            foreach ($exposeTotals as $expField => $expVal) {
                $fieldAggregates[$expField] = $expVal;
            }
        }

        // Second pass: resolve expression fields + emit final totals
        foreach ($totalsConfig as $field => $aggregation) {
            if (is_int($field)) {
                $field = $aggregation;
                $aggregation = 'sum';
            }

            if (!isset($columnMap[$field])) {
                // Field not declared as a top-level column.
                // Exception: expose-alias fields (e.g. paid_total / due_total from
                // payment_schedule.payments.expose) are side-effects of a ps column and
                // are never registered in $columnMap.  If buildExposeTotals() already
                // computed a value for this field, emit it directly and move on.
                if (isset($exposeFields[$field]) && isset($fieldAggregates[$field])) {
                    $totals[$field] = $fieldAggregates[$field];
                }
                continue;
            }

            $column = $columnMap[$field];

            // Expression field: calculate from component aggregates
            if (isset($column['expression'])) {
                try {
                    // Replace dots with underscores for ExpressionLanguage
                    $safeExpression = str_replace('.', '_', $column['expression']);
                    $safeVariables = array_combine(
                        array_map(fn($k) => str_replace('.', '_', $k), array_keys($fieldAggregates)),
                        array_values($fieldAggregates)
                    );
                    $value = $this->expressionLanguage->evaluate(
                        $safeExpression,
                        $safeVariables
                    );
                    $totals[$field] = $this->formatTotalValue($value, $column);
                } catch (\Throwable $e) {
                    $totals[$field] = null;
                }
            } else {
                // Direct field (including expose-aliases merged above)
                if (isset($fieldAggregates[$field])) {
                    // count aggregates are always emitted as integers — they represent
                    // row counts, not sums, so decimal rounding is meaningless.
                    if ($aggregation === 'count') {
                        $totals[$field] = (int) $fieldAggregates[$field];
                    } else {
                        $totals[$field] = $this->formatTotalValue($fieldAggregates[$field], $column);
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * Compute grand totals for all relation_aggregate columns requested in $totalsConfig.
     *
     * Strategy: wrap the existing correlated subquery in a SELECT SUM/AVG/MIN/MAX/COUNT
     * over the full (filtered) primary-model set. This executes one SQL query per RA
     * totals column — no N+1.
     *
     * For COUNT columns the outer aggregate is SUM (sum of per-row counts = global count).
     * For SUM/AVG/MIN/MAX/GROUP_CONCAT columns the outer aggregate matches the inner function
     * (SUM→SUM, AVG→AVG, etc.). GROUP_CONCAT totals are skipped (not meaningful as a grand total).
     *
     * The correlated subquery is built exactly as for per-row SELECT, so through-chains,
     * structured WHERE conditions, and expression WHERE all work identically.
     *
     * Implementation:
     *   SELECT SUM((SELECT COUNT(*) FROM tasks WHERE ...)) FROM primary_table WHERE <filters>
     *   SELECT SUM((SELECT SUM(area) FROM estate_sells WHERE s.house_id = estate_houses.house_id)) FROM estate_houses WHERE <filters>
     *
     * This avoids re-paginating and avoids in-memory collection loading.
     *
     * @param Builder $query       Filtered base query clone (no pagination)
     * @param mixed   $totalsConfig Totals config from report
     * @param array   $columnMap   [field => columnConfig] map
     * @return array<string, float|int|null>  [field => total_value]
     */
    protected function buildRelationAggregateTotals(Builder $query, mixed $totalsConfig, array $columnMap): array
    {
        $result = [];

        if (empty($totalsConfig) || !isset($this->modelInstance)) {
            return $result;
        }

        foreach ($totalsConfig as $field => $aggregation) {
            if (is_int($field)) {
                $field       = $aggregation;
                $aggregation = 'sum';
            }

            // Only handle relation_aggregate columns
            if (!isset($columnMap[$field]) || ($columnMap[$field]['type'] ?? null) !== 'relation_aggregate') {
                continue;
            }

            $column    = $columnMap[$field];
            $aggConfig = $column['aggregate'] ?? [];
            $alias     = $field;
            $fn        = strtoupper($aggConfig['function'] ?? 'COUNT');

            // GROUP_CONCAT grand totals are not meaningful — skip
            if ($fn === 'GROUP_CONCAT') {
                continue;
            }

            // Validate alias
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                continue;
            }

            $relationName = $aggConfig['relation'] ?? null;
            if (!$relationName || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)
                || !method_exists($this->modelInstance, $relationName)) {
                continue;
            }

            try {
                $relationObj  = $this->modelInstance->{$relationName}();
                $relatedModel = $relationObj->getRelated();
                $relatedTable = $relatedModel->getTable();
            } catch (\Throwable) {
                continue;
            }

            $throughChain = $aggConfig['through'] ?? [];

            if (!empty($throughChain)) {
                $subquerySql = $this->buildThroughSubquery(
                    fn           : $fn,
                    firstRelObj  : $relationObj,
                    firstRelTable: $relatedTable,
                    firstRelModel: $relatedModel,
                    throughChain : $throughChain,
                    aggConfig    : $aggConfig,
                    alias        : $alias
                );
            } else {
                [$fkColumn, $pkColumn, $primaryTable] = $this->resolveRelationKeys($relationObj);
                if ($fkColumn === null) {
                    continue;
                }

                $subquerySql = $this->buildCorrelatedSubquery(
                    fn           : $fn,
                    relatedTable : $relatedTable,
                    fkColumn     : $fkColumn,
                    pkColumn     : $pkColumn,
                    primaryTable : $primaryTable,
                    aggConfig    : $aggConfig,
                    alias        : $alias
                );
            }

            if ($subquerySql === null) {
                continue;
            }

            // Outer aggregate: for COUNT per-row → SUM of counts = global total.
            // For SUM/AVG/MIN/MAX — use the same function as the inner aggregate.
            $outerFn = match ($fn) {
                'COUNT' => 'SUM',
                default => $fn,
            };

            try {
                $total = $query->clone()
                    ->reorder()
                    ->selectRaw("{$outerFn}({$subquerySql}) AS __ra_total")
                    ->value('__ra_total');

                $result[$field] = $total !== null ? (float) $total : null;
            } catch (\Throwable) {
                // Query failed (e.g., no connection in unit tests) — skip this field
                $result[$field] = null;
            }
        }

        return $result;
    }

    /**
     * Return a map of expose target-keys to the payment_schedule column that declares them.
     *
     * Example: if a payment_schedule column has payments.expose = ['paid_total' => 'paid_total'],
     * the returned array is ['paid_total' => <column-config-array>].
     *
     * A target-key present in multiple ps columns is overwritten by the last one (edge case).
     *
     * @return array<string, array>  [targetKey => columnConfig]
     */
    protected function collectExposeFields(): array
    {
        $map = [];
        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['type'] ?? null) !== 'payment_schedule') {
                continue;
            }
            $expose = $column['payments']['expose'] ?? [];
            foreach ($expose as $sourceKey => $targetKey) {
                if (is_string($targetKey) && $targetKey !== '') {
                    $map[$targetKey] = $column;
                }
            }
        }
        return $map;
    }

    /**
     * Compute SUM(paid_total) and SUM(due_total) for expose-alias fields across the
     * full filtered dataset (no pagination).
     *
     * Issues exactly ONE SELECT per payment_schedule column that contributes expose fields
     * requested in totals. For the typical case of a single ps column with two expose keys
     * (paid_total / due_total) this is one query total.
     *
     * The query mirrors buildPaymentScheduleMap() but:
     * - Uses all deal IDs from the filtered query (no page limit).
     * - Returns only scalar SUM values, not per-row schedules.
     *
     * Performance note: for large datasets (> 10 k deals) this SELECT can be slow because
     * it materialises all primary-key values first (via pluck). If this becomes a bottleneck,
     * replace the pluck+whereIn with a correlated subquery / EXISTS pattern.
     *
     * @param  Builder                $query        Clone of the base filtered query (no pagination).
     * @param  array<string, array>   $exposeFields [targetKey => psColumnConfig] from collectExposeFields().
     * @return array<string, float>                 [targetKey => total]
     */
    protected function buildExposeTotals(Builder $query, array $exposeFields): array
    {
        if (empty($exposeFields)) {
            return [];
        }

        // Group expose keys by their source ps-column (keyed by $column['field']).
        // Multiple expose keys from the same ps-column share one SQL query.
        $byPsColumn = [];
        foreach ($exposeFields as $targetKey => $columnConfig) {
            $psField = $columnConfig['field'];
            $byPsColumn[$psField]['config']       = $columnConfig;
            $byPsColumn[$psField]['targetKeys'][]  = $targetKey;
        }

        // Collect all primary-key values for the filtered dataset — one pluck query.
        // We need these to constrain the finances SELECT.
        $pkColumn  = $this->modelInstance->getKeyName();
        $pkValues  = $query->clone()->reorder()->pluck(
            $this->modelInstance->getTable() . '.' . $pkColumn
        )->filter()->unique()->values()->toArray();

        if (empty($pkValues)) {
            return array_fill_keys(array_keys($exposeFields), 0.0);
        }

        $result = [];

        foreach ($byPsColumn as $psField => $meta) {
            $column       = $meta['config'];
            $payments     = $column['payments'] ?? [];
            $relationName = $payments['relation']    ?? 'finances';
            $typesIds     = $payments['types_id']    ?? [3786, 3788];
            $statusPaid   = (int) ($payments['status_paid'] ?? 1);
            $statusDue    = (int) ($payments['status_due']  ?? 3);

            // Safety: validate relation name before using it for reflection.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $relationName)) {
                foreach ($meta['targetKeys'] as $tk) {
                    $result[$tk] = null;
                }
                continue;
            }

            if (!method_exists($this->modelInstance, $relationName)) {
                foreach ($meta['targetKeys'] as $tk) {
                    $result[$tk] = null;
                }
                continue;
            }

            try {
                $relationObj = $this->modelInstance->{$relationName}();
            } catch (\Throwable) {
                foreach ($meta['targetKeys'] as $tk) {
                    $result[$tk] = null;
                }
                continue;
            }

            if (!($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany)) {
                foreach ($meta['targetKeys'] as $tk) {
                    $result[$tk] = null;
                }
                continue;
            }

            $fkColumn     = $relationObj->getForeignKeyName();   // e.g. deal_id
            $relatedTable  = $relationObj->getRelated()->getTable(); // e.g. finances

            $safeTypesIds = array_values(array_filter(
                (array) $typesIds,
                fn($v) => is_int($v) || (is_string($v) && ctype_digit($v))
            ));
            $safeTypesIds = array_map('intval', $safeTypesIds);

            try {
                $finRows = \Illuminate\Support\Facades\DB::connection('macrodata')
                    ->table($relatedTable)
                    ->whereIn($fkColumn, $pkValues)
                    ->when(!empty($safeTypesIds), fn($q) => $q->whereIn('types_id', $safeTypesIds))
                    ->whereIn('status', [$statusPaid, $statusDue])
                    ->get([$fkColumn, 'summa', 'status']);
            } catch (\Throwable) {
                foreach ($meta['targetKeys'] as $tk) {
                    $result[$tk] = null;
                }
                continue;
            }

            $paidTotal = 0.0;
            $dueTotal  = 0.0;

            foreach ($finRows as $fin) {
                $summa  = (float) ($fin->summa  ?? 0);
                $status = (int)   ($fin->status ?? 0);
                if ($status === $statusPaid) {
                    $paidTotal += $summa;
                }
                if ($status === $statusDue) {
                    $dueTotal += $summa;
                }
            }

            // Map source keys to target keys.
            // Standard expose sources are 'paid_total' → targetKey and 'due_total' → targetKey.
            // We rebuild the source→target map from the column config to assign correctly.
            $expose = $column['payments']['expose'] ?? [];
            $sourceToTarget = [];
            foreach ($expose as $sourceKey => $targetKey) {
                if (is_string($targetKey) && $targetKey !== '') {
                    $sourceToTarget[$sourceKey] = $targetKey;
                }
            }

            $sourceValues = ['paid_total' => $paidTotal, 'due_total' => $dueTotal];

            foreach ($meta['targetKeys'] as $tk) {
                // Find which source key maps to this target key.
                $sourceKey = array_search($tk, $sourceToTarget, true);
                if ($sourceKey !== false && isset($sourceValues[$sourceKey])) {
                    $result[$tk] = $sourceValues[$sourceKey];
                } else {
                    // Target key does not correspond to a known source — return 0.
                    $result[$tk] = 0.0;
                }
            }
        }

        return $result;
    }

    /**
     * Calculate aggregate for direct model field.
     *
     * When $aggregation is 'count', uses COUNT(*) — the total number of filtered
     * rows — rather than COUNT(field), which would only count non-null values.
     * This matches the semantics of footer: {agg: 'count'}: show the row count
     * in this footer cell regardless of the column's actual field value.
     */
    protected function calculateDirectAggregate(Builder $query, string $field, string $aggregation): ?float
    {
        try {
            return match ($aggregation) {
                'sum'   => $query->sum($field),
                // COUNT(*) — total filtered rows, not COUNT(field) non-null values.
                // Consistent with footer: {agg: 'count'} semantics.
                'count' => (float) $query->count(),
                'avg'   => $query->avg($field),
                'min'   => $query->min($field),
                'max'   => $query->max($field),
                default => $query->sum($field),
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Calculate aggregate for relation field (dot notation)
     */
    protected function calculateRelationAggregate(Builder $query, string $field, string $aggregation): ?float
    {
        // For relation fields, we need to use a subquery or join
        // Simplified approach: get all IDs and aggregate on related model
        try {
            $parts = explode('.', $field);
            $relationField = array_pop($parts);
            $relationChain = implode('.', $parts);

            // Get the base query results with the relation loaded
            $results = $query->get();

            $value = match ($aggregation) {
                'sum' => $results->pluck("{$relationChain}.{$relationField}")->flatten()->sum(),
                'count' => $results->pluck("{$relationChain}.{$relationField}")->flatten()->count(),
                'avg' => $results->pluck("{$relationChain}.{$relationField}")->flatten()->avg(),
                'min' => $results->pluck("{$relationChain}.{$relationField}")->flatten()->min(),
                'max' => $results->pluck("{$relationChain}.{$relationField}")->flatten()->max(),
                default => $results->pluck("{$relationChain}.{$relationField}")->flatten()->sum(),
            };

            return $value ?? 0;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Format total value based on column type
     */
    protected function formatTotalValue(float|int|null $value, array $column): float|int|null
    {
        if ($value === null) {
            return null;
        }

        $type = $column['type'] ?? 'text';
        $format = $column['format'] ?? null;

        return match ($type) {
            'currency', 'number' => $format ? round($value, (int) strpos($format, '.')) : round($value, 2),
            default => $value,
        };
    }

    /**
     * Get full model class name
     */
    protected function getModelClass(): string
    {
        return "App\\Models\\MacroData\\{$this->primaryModel}";
    }

    /**
     * Return empty response structure
     */
    protected function getEmptyResponse(Report $report): array
    {
        return [
            'id' => $report->id,
            'title' => json_decode($report->getRawOriginal('title'), true),
            'description' => json_decode($report->getRawOriginal('description'), true),
            'columns' => $this->config['columns'] ?? [],
            'rows' => [],
            'meta' => [
                'total' => 0,
                'page' => 1,
                'per_page' => $this->config['pagination']['default'] ?? 20,
                'last_page' => 1,
            ],
            'filters_available' => [],
            'filters_applied' => [],
            'totals' => [],
            // Same whitelisted projection as the happy path.
            // If $this->config is already set (connect() failed after config was
            // loaded), forward primary_filter so the header widget is not lost.
            'config' => $this->buildPublicConfigProjection(),
        ];
    }

    // -------------------------------------------------------------------------
    // Async filter options (server-side search for high-cardinality fields)
    // -------------------------------------------------------------------------

    /**
     * Return matching options for an async_select filter field.
     *
     * Security contract:
     *   1. $field MUST be declared as filterable in the report config AND
     *      have filter_type='async_select'. Any other field is rejected (403).
     *   2. The dot-path relation chain is resolved exclusively through Eloquent
     *      relation metadata — no user input is injected into table/column names.
     *   3. $q is passed as a PDO-bound parameter (no manual quoting needed).
     *
     * Algorithm for dot-path fields (e.g. "estateDeals.contactsBuy.contacts_buy_name"):
     *   - Walk every relation hop via applySortViaJoin-style Eloquent reflection to
     *     determine related table and the leaf column.
     *   - Build a subquery: SELECT DISTINCT <leaf_col> FROM <related_table>
     *     [WHERE <leaf_col> LIKE '%q%'] ORDER BY <leaf_col> LIMIT $limit.
     *   - Return [{ value: <leaf_value>, label: <leaf_value> }, ...].
     *     value == label == the raw field string (same as existing select options).
     *     Filtering later re-uses applyRelationFilter which does WHERE leaf_col = value.
     *
     * For direct (non-dot) fields: same but on the primary model's table.
     *
     * @param  Report      $report
     * @param  User        $user
     * @param  string      $field   Dot-path or direct column name from report config
     * @param  string|null $q       Optional search string (LIKE '%q%')
     * @param  int         $limit   Max results (1–100)
     * @return array{options: array, async: bool}|null  null if rejected
     */
    public function searchAsyncFilterOptions(Report $report, Company $company, User $user, string $field, ?string $q, int $limit): ?array
    {
        $this->config = $report->config;
        $this->config['_report_id'] = $report->id;
        $this->primaryModel = $this->config['primary_model'] ?? 'EstateDeals';

        // Connect to MacroData
        try {
            $this->connectionService->connect($company);
        } catch (\Exception $e) {
            return null;
        }

        // Resolve per-company variable placeholders ({"$company_var": "..."}) in config.
        // Must run after connect() and before applyGlobalWheres() — otherwise whereIn values
        // stay as raw placeholder objects and MySQL receives 0-rows (company_var = [] → IN (0,0)).
        if ($this->configResolver !== null) {
            $this->config = $this->configResolver->resolve($this->config, $company);
        }

        // Resolve model instance (needed for both column and extra_filter paths)
        $modelClass = $this->getModelClass();
        if (!class_exists($modelClass)) {
            return null;
        }
        $this->modelInstance = new $modelClass;

        // Clamp limit
        $limit = max(1, min(100, $limit));

        // Check extra_filters first — they declare their own options_source.
        $extraFilterDef = $this->findExtraFilter($field);
        if ($extraFilterDef !== null) {
            $options = $this->fetchAsyncOptionsForExtraFilter($extraFilterDef, $q, $limit);
            return $options !== null ? ['options' => $options, 'async' => true] : null;
        }

        // Validate: field must exist in config, be filterable, and be async_select.
        $column = $this->findAsyncSelectColumn($field);
        if ($column === null) {
            return null; // not whitelisted
        }

        // If filter_field is declared on the column, use it as the actual search field.
        // Example: column field=deal_id, filter_field=agreement_number — the user searches
        // by agreement_number values, not by the numeric deal_id.
        $searchField = $column['filter_field'] ?? $field;

        $options = str_contains($searchField, '.')
            ? $this->fetchAsyncOptionsForRelation($searchField, $q, $limit)
            : $this->fetchAsyncOptionsForDirect($searchField, $q, $limit);

        return [
            'options' => $options ?? [],
            'async'   => true,
        ];
    }

    /**
     * Fetch async options for an extra_filter with has_any_pivot operation.
     *
     * Reads options_source from the extra_filter definition:
     *   options_source.model        — MacroData model class name (e.g. 'Tags')
     *   options_source.value_field  — attribute to use as option value (e.g. 'id')
     *   options_source.label_field  — attribute to use as option label (e.g. 'tags_name')
     *
     * The model is resolved as App\Models\MacroData\{model}. No report.config['where']
     * scoping is applied — the full set of available tags is always returned.
     *
     * @return array<int, array{value: mixed, label: string}>|null  null if misconfigured
     */
    protected function fetchAsyncOptionsForExtraFilter(array $def, ?string $q, int $limit): ?array
    {
        $source      = $def['options_source'] ?? null;
        $modelName   = $source['model']       ?? null;
        $valueField  = $source['value_field'] ?? null;
        $labelField  = $source['label_field'] ?? null;

        if (!$modelName || !$valueField || !$labelField) {
            return null;
        }

        // Validate field names as safe identifiers
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $valueField)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $labelField)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $modelName)
        ) {
            return null;
        }

        $modelClass = "App\\Models\\MacroData\\{$modelName}";
        if (!class_exists($modelClass)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model $sourceModel */
        $sourceModel = new $modelClass;
        $table       = $sourceModel->getTable();

        $query = $sourceModel->newQuery()
            ->select(["{$table}.{$valueField}", "{$table}.{$labelField}"])
            ->orderBy("{$table}.{$labelField}");

        if ($q !== null && $q !== '') {
            $query->where("{$table}.{$labelField}", 'LIKE', '%' . $q . '%');
        }

        return $query->limit($limit)->get()->map(fn($row) => [
            'value' => $row->{$valueField},
            'label' => (string) ($row->{$labelField} ?? ''),
        ])->values()->toArray();
    }

    /**
     * Locate a column config that matches $field AND has filter_type='async_select'.
     * Returns null if not found (field not in config, not filterable, or not async_select).
     */
    protected function findAsyncSelectColumn(string $field): ?array
    {
        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['field'] ?? null) !== $field) {
                continue;
            }
            if (!($column['filterable'] ?? true)) {
                return null;
            }
            if (($column['filter_type'] ?? null) !== 'async_select') {
                return null;
            }
            return $column;
        }
        return null;
    }

    /**
     * Fetch async options for a direct (non-dot) field on the primary model's table.
     *
     * Scoped to report's where-clauses: starts from the primary model query with all
     * report.config['where'] conditions applied so that only values visible in this
     * report are returned. User-supplied filter params are intentionally NOT applied
     * here — options must represent the full filterable universe, not a subset already
     * narrowed by the user's current filter selections.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function fetchAsyncOptionsForDirect(string $field, ?string $q, int $limit): array
    {
        // Validate field name as safe identifier (no injection possible)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return [];
        }

        $table = $this->modelInstance->getTable();

        // Start from primary model with all report-level where conditions applied.
        // This scopes the DISTINCT options to the report's visible data subset.
        $query = $this->modelInstance->newQuery();
        $this->applyGlobalWheres($query);

        $query->reorder()
            ->select("{$table}.{$field}")
            ->distinct()
            ->limit($limit);

        if ($q !== null && $q !== '') {
            $query->where("{$table}.{$field}", 'LIKE', '%' . $q . '%');
        }

        $query->orderBy("{$table}.{$field}");

        return $query->pluck($field)
            ->filter(fn($v) => $v !== null && $v !== '')
            ->map(fn($v) => ['value' => $v, 'label' => (string) $v])
            ->values()
            ->toArray();
    }

    /**
     * Fetch async options for a dot-path relation field.
     *
     * Scoped to report's where-clauses: starts from the primary model with all
     * report.config['where'] conditions applied, then narrows to records that have
     * a matching related-model row via whereHas, and finally SELECT DISTINCTs
     * the leaf column from the related table using an EXISTS subquery.
     *
     * This ensures that for e.g. "Дебиторская задолженность" (status=3,
     * types_id IN [3786,3788]), the contactsBuy.contacts_buy_name options only
     * include counterparties that actually appear in the scoped finance rows.
     *
     * Algorithm:
     *   1. Build base query on primary model with report where-clauses.
     *   2. Walk relation chain via Eloquent reflection to resolve leaf model + FK chain.
     *   3. For each relation hop (BelongsTo / HasOne / HasMany) build a LEFT JOIN
     *      alias from the primary model query perspective.
     *   4. Add DISTINCT SELECT on leaf column + LIKE filter + ORDER + LIMIT.
     *
     * Implementation strategy: rather than building raw SQL JOINs (fragile), we
     * use whereHas + a subquery on the leaf relation's model table, constrained
     * by an EXISTS against the scoped primary model. Concretely:
     *   SELECT DISTINCT cb.contacts_buy_name
     *   FROM contacts_buy cb
     *   WHERE EXISTS (
     *     SELECT 1 FROM finances f
     *     JOIN estate_deals ed ON ed.deal_id = f.deal_id
     *     WHERE ed.contacts_buy_id = cb.contacts_buy_id
     *       AND f.status = 3
     *       AND f.types_id IN (3786, 3788)
     *   )
     *   AND cb.contacts_buy_name LIKE '%q%'
     *   ORDER BY cb.contacts_buy_name LIMIT N
     *
     * In Eloquent terms: we build the primary-model scoped query and add a
     * whereHas chain up to the leaf relation, then invert the direction:
     * query the leaf model with a whereHas back to the primary.
     *
     * Inversion approach (simpler & DB-agnostic):
     *   - Build primary query with wheres.
     *   - Use ->whereHas(full_relation_chain) subquery: the leaf model queries with
     *     whereHas pointing back through the inverse chain.
     *
     * Practical approach used here (avoids complex inverse-chain resolution):
     *   - Start from primary model with all wheres applied.
     *   - JOIN-walk to reach the leaf table using LEFT JOINs (same as applySortViaJoin).
     *   - SELECT DISTINCT leaf column from this joined primary query, then
     *     add LIKE + ORDER + LIMIT on the projected column.
     *
     * Only BelongsTo / HasOne chains are walked for JOINs (HasMany hops could
     * produce duplicate rows but here we SELECT DISTINCT so it is safe). HasMany
     * is therefore also permitted.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function fetchAsyncOptionsForRelation(string $dotPath, ?string $q, int $limit): array
    {
        $parts     = explode('.', $dotPath);
        $leafField = array_pop($parts); // e.g. "contacts_buy_name"

        // Validate leaf field
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $leafField)) {
            return [];
        }

        // Validate all relation segment names up front (security: no special chars).
        foreach ($parts as $seg) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $seg)) {
                return [];
            }
        }

        // Pre-walk the relation chain to validate all hops exist on the models
        // before touching the DB connection. This allows early-return without
        // ever calling newQuery() when a relation is missing (important for tests
        // and for fail-fast behaviour without a live DB connection).
        $currentModel = $this->modelInstance;
        $resolvedHops = []; // array of {relationObj, relatedModel, relatedTable, joinAlias}

        foreach ($parts as $relationName) {
            if (!method_exists($currentModel, $relationName)) {
                return [];
            }

            $relationObj  = $currentModel->{$relationName}();
            $relatedModel = $relationObj->getRelated();
            $relatedTable = $relatedModel->getTable();
            $joinAlias    = 'async_join_' . $relationName;

            // Validate relation type up front
            if (!($relationObj instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)
                && !($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne)
                && !($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasMany)
            ) {
                return []; // unsupported relation type
            }

            $resolvedHops[] = compact('relationObj', 'relatedModel', 'relatedTable', 'joinAlias');
            $currentModel   = $relatedModel;
        }

        // All hops valid — now build the scoped query.
        // Start from primary model with report where-clauses applied (context scoping).
        $query = $this->modelInstance->newQuery();
        $this->applyGlobalWheres($query);

        // Build LEFT JOINs along the pre-resolved relation chain.
        $currentTableAlias = $this->modelInstance->getTable();

        foreach ($resolvedHops as $hop) {
            /** @var \Illuminate\Database\Eloquent\Relations\Relation $relationObj */
            $relationObj  = $hop['relationObj'];
            $relatedTable = $hop['relatedTable'];
            $joinAlias    = $hop['joinAlias'];

            if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                $fk       = $relationObj->getForeignKeyName();
                $ownerKey = $relationObj->getOwnerKeyName();
                $query->leftJoin(
                    "{$relatedTable} AS {$joinAlias}",
                    "{$currentTableAlias}.{$fk}",
                    '=',
                    "{$joinAlias}.{$ownerKey}"
                );
            } else {
                // HasOne / HasMany (pre-validated in resolvedHops)
                $localKey = $relationObj->getLocalKeyName();
                $fk       = $relationObj->getForeignKeyName();
                $query->leftJoin(
                    "{$relatedTable} AS {$joinAlias}",
                    "{$currentTableAlias}.{$localKey}",
                    '=',
                    "{$joinAlias}.{$fk}"
                );
            }

            $currentTableAlias = $joinAlias;
        }

        // Now SELECT DISTINCT the leaf column from the joined query.
        $leafColRef = "{$currentTableAlias}.{$leafField}";

        $query->reorder()
            ->select($leafColRef)
            ->distinct()
            ->whereNotNull($leafColRef)
            ->where($leafColRef, '!=', '')
            ->limit($limit);

        if ($q !== null && $q !== '') {
            $query->where($leafColRef, 'LIKE', '%' . $q . '%');
        }

        $query->orderBy($leafColRef);

        return $query->pluck($leafField)
            ->filter(fn($v) => $v !== null && $v !== '')
            ->map(fn($v) => ['value' => $v, 'label' => (string) $v])
            ->values()
            ->toArray();
    }
}
