<?php

declare(strict_types=1);

namespace App\Services\MacroData;

use App\Models\Company;
use App\Models\Widget;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WidgetDataService — computes the aggregated, chart-ready payload for a widget.
 *
 * Design decisions:
 *
 *   Engine choice: we implement the SQL GROUP BY aggregation inline (pattern
 *   mirrored from DataProbeService::query) rather than delegating to
 *   ReportDataService (too heavy — requires Report model + columns + pagination)
 *   or DataProbeService (single-aggregate per call + PII deny-list + strict
 *   orderBy whitelist that doesn't apply to AI-authored widget configs).
 *   Widget configs are authored by the AI tool, not by end-users, so the threat
 *   model is different from probe_data.
 *
 *   Identifier safety: all field names that flow into selectRaw / groupBy are
 *   validated against SAFE_IDENT_REGEX before touching SQL. Values always flow
 *   through Eloquent parameterised binds (no manual quoting).
 *
 *   Period injection (O4): if config.period_field is present, period filtering
 *   is applied via whereBetween. Accepts:
 *     - ?period_from=YYYY-MM&period_to=YYYY-MM  (range, inclusive)
 *     - ?period=YYYY-MM                          (single month, backward-compat)
 *   Default when period_field is set but no params given: last 12 months
 *   (temporal widgets showing line/bar dynamics) or current month (others) —
 *   callers pass the resolved period strings. Widgets without period_field are
 *   unaffected (meta.period_applied=false).
 *
 *   ConfigResolver: $company_var placeholders are expanded before any query
 *   building, consistent with ReportDataService.getData().
 *
 *   Hard cap: MAX_ROWS_PER_WIDGET (1 000) prevents giant result sets. A chart
 *   with 1000 data points is already unusable; the cap protects MySQL and the
 *   response payload.
 *
 *   Dashboard batch (DashboardController::data): calls compute() for each
 *   visible widget sequentially. Each widget may reference a different
 *   primary_model, so per-widget queries are unavoidable at MVP stage.
 *
 *   Relation group_by (R1): group_by.fields may contain dot-paths of the form
 *   "<relationName>.<columnName>" (single hop, e.g. "usersManager.users_name").
 *   For each dot-path field the service adds a LEFT JOIN via Eloquent relation
 *   metadata (method_exists → relation instance → getForeignKeyName /
 *   getOwnerKeyName). Only BelongsTo and HasOne are accepted — HasMany would
 *   produce row duplication and corrupt aggregate counts. The joined column is
 *   aliased as "<relation>__<column>" (double-underscore) in the SELECT and
 *   grouped by that alias. chart.label_field should reference the same dot-path
 *   (e.g. "usersManager.users_name") which is transparently mapped to the alias.
 *   Relation and column names are validated against SAFE_IDENT_REGEX. Table
 *   names and key names always come from Eloquent internals — never from config.
 *
 *   Temporal group_by (T1): group_by.fields may contain temporal tokens of the
 *   form "<field>|<granularity>" (e.g. "deal_date|month"). The field part must
 *   satisfy SAFE_IDENT_REGEX; the granularity must be one of the TEMPORAL_GRANULARITIES
 *   whitelist. The service emits DATE_FORMAT(`table`.`field`, '<mask>') AS
 *   `<alias>` where the mask comes from the whitelist map — never from the
 *   config string. This is MySQL-specific syntax (MacroData is MySQL).
 *   alias format: "<field>__<granularity>" (double-underscore, mirrors relation alias).
 *   chart.label_field should reference the same temporal token (e.g. "deal_date|month")
 *   which is transparently mapped to the alias in label extraction.
 *   ORDER BY for temporal aliases is chronological because DATE_FORMAT strings
 *   are lexicographically sortable for month/year/day granularities.
 *
 *   Exclude empty labels (E1): by default, groups where the label value is NULL
 *   or empty string ('') are excluded from the result. This removes "unnamed"
 *   rows (e.g. leads with no assigned manager) that produce meaningless chart
 *   bars. Disable with config.exclude_empty_labels = false.
 *
 *   Top-N + Others (N1): chart.limit (integer) keeps the top-N rows by the
 *   value field. Remaining rows are collapsed into a single "Другие" entry
 *   (summing numeric values) when chart.others_label is set (any non-empty
 *   string). Without others_label — plain top-N truncation. meta.others_count
 *   reports how many groups were collapsed.
 */
class WidgetDataService
{
    /**
     * Simple identifier regex — same constraint as ExpressionSqlTranslator /
     * DataProbeService: no dots, no spaces, no quotes.
     */
    protected const SAFE_IDENT_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Dot-path regex for relation group_by fields: "<relation>.<column>".
     * Both segments must individually satisfy SAFE_IDENT_REGEX.
     * Single-hop only (no chained relations) — multi-hop would require
     * recursive JOIN resolution and risks row duplication at each join.
     */
    protected const RELATION_DOT_PATH_REGEX = '/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$/';

    /**
     * Temporal token regex: "<field>|<granularity>".
     * The field segment must satisfy SAFE_IDENT_REGEX; the granularity
     * is validated against the TEMPORAL_GRANULARITIES whitelist separately.
     */
    protected const TEMPORAL_TOKEN_REGEX = '/^([a-zA-Z_][a-zA-Z0-9_]*)\|(month|year|day|week)$/';

    /**
     * Whitelisted temporal granularities and their DATE_FORMAT masks (MySQL).
     * Masks are hard-coded here — NEVER taken from config strings.
     */
    protected const TEMPORAL_GRANULARITIES = [
        'month' => '%Y-%m',
        'year'  => '%Y',
        'day'   => '%Y-%m-%d',
        'week'  => '%x-W%v',   // ISO year + ISO week (e.g. "2026-W21")
    ];

    /**
     * JOIN alias prefix for relation group_by joins.
     * Distinct from ReportDataService's "sort_join_" to avoid any naming
     * conflicts if both services ever coexist in the same query scope.
     */
    protected const JOIN_ALIAS_PREFIX = 'widget_join_';

    /**
     * Whitelisted aggregate functions for selectRaw.
     */
    protected const AGGREGATE_FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * Hard cap on result rows. Charts with >1000 points are visually unusable;
     * the cap also protects the response payload size.
     */
    public const MAX_ROWS_PER_WIDGET = 1000;

    /**
     * Hard cap on widgets processed in a single dashboard batch request.
     * Prevents an adversarial dashboard from triggering 100+ MacroData queries.
     */
    public const MAX_WIDGETS_PER_DASHBOARD = 20;

    public function __construct(
        protected ConnectionService $connectionService,
        protected ConfigResolver $configResolver,
    ) {}

    /**
     * Compute the chart-ready payload for a single widget.
     *
     * Period params (period_from / period_to / period) are resolved by the
     * caller (WidgetController::data, DashboardController::data) and passed
     * as pre-parsed strings. Both nullable; when period_field is set and no
     * params are given the service applies a default range (see normalizePeriodRange).
     *
     * @param  Widget        $widget       The widget entity.
     * @param  Company       $company      Active company.
     * @param  string|null   $periodFrom   YYYY-MM start (inclusive). Null = default.
     * @param  string|null   $periodTo     YYYY-MM end (inclusive). Null = default.
     * @return array{
     *   labels: list<string>,
     *   datasets: list<array{label: string, data: list<int|float>}>,
     *   meta: array<string, mixed>
     * }
     */
    public function compute(
        Widget $widget,
        Company $company,
        ?string $periodFrom = null,
        ?string $periodTo   = null,
    ): array {
        $config = $widget->config ?? [];

        // ------------------------------------------------------------------
        // 1. Connect to MacroData for the active company.
        // ------------------------------------------------------------------
        try {
            $this->connectionService->connect($company);
        } catch (\Exception $e) {
            Log::warning('WidgetDataService: MacroData connection failed', [
                'widget_id'  => $widget->id,
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->emptyPayload($periodFrom, $periodTo, periodApplied: false);
        }

        // ------------------------------------------------------------------
        // 2. Resolve $company_var placeholders.
        // ------------------------------------------------------------------
        $unresolvedVars = [];
        $config = $this->configResolver->resolve($config, $company, $unresolvedVars);

        // ------------------------------------------------------------------
        // 3. Resolve the primary Eloquent model.
        // ------------------------------------------------------------------
        $primaryModel = $config['primary_model'] ?? null;
        if (!$primaryModel) {
            return $this->emptyPayload($periodFrom, $periodTo, periodApplied: false, unresolvedVars: $unresolvedVars);
        }

        $modelClass = $this->resolveModelClass($primaryModel);
        if ($modelClass === null) {
            Log::warning('WidgetDataService: model not found', [
                'widget_id'     => $widget->id,
                'primary_model' => $primaryModel,
            ]);
            return $this->emptyPayload($periodFrom, $periodTo, periodApplied: false, unresolvedVars: $unresolvedVars);
        }

        // ------------------------------------------------------------------
        // 4. Build Eloquent query base.
        // ------------------------------------------------------------------
        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = new $modelClass;
        $query = $instance->newQuery();

        // ------------------------------------------------------------------
        // 5. Apply global where[] conditions from config.
        //    Pass primary table name so that field references are qualified
        //    with the table — necessary when relation JOINs are added in step 7.
        // ------------------------------------------------------------------
        $this->applyWheres($query, $config['where'] ?? [], $instance->getTable());

        // ------------------------------------------------------------------
        // 6. Apply period range filter.
        //    Applies only when config.period_field is a safe, non-empty string.
        //    normalizePeriodRange() applies defaults when params are absent.
        // ------------------------------------------------------------------
        $periodField   = $config['period_field'] ?? null;
        $periodApplied = false;
        $effectivePeriodFrom = null;
        $effectivePeriodTo   = null;

        if ($periodField && is_string($periodField) && $this->isSafeIdentifier($periodField)) {
            $range = $this->normalizePeriodRange($periodFrom, $periodTo, $config);
            if ($range !== null) {
                [$effectivePeriodFrom, $effectivePeriodTo] = $range;
                $primaryTable = $instance->getTable();
                $query->whereBetween("{$primaryTable}.{$periodField}", [
                    $effectivePeriodFrom,
                    $effectivePeriodTo,
                ]);
                $periodApplied = true;
            }
        }

        // ------------------------------------------------------------------
        // 7. Build GROUP BY + aggregate SELECT expressions.
        //
        // group_by.fields supports three shapes:
        //   a) bare identifier  "category"               → groups on primary table column
        //   b) dot-path         "usersManager.users_name" → LEFT JOIN relation,
        //      groups on joined column aliased as "usersManager__users_name"
        //   c) temporal token   "deal_date|month"         → DATE_FORMAT on primary
        //      table column, aliased as "deal_date__month"
        //
        // For dot-path fields a JOIN is added via Eloquent relation metadata
        // (BelongsTo / HasOne only). The column alias uses double-underscore
        // as separator to avoid collisions with real column names.
        //
        // Temporal fields are MySQL-specific (DATE_FORMAT). Granularity masks
        // come from TEMPORAL_GRANULARITIES whitelist — never from config strings.
        // ------------------------------------------------------------------
        $groupFields    = $config['group_by']['fields'] ?? [];
        $aggregates     = $config['aggregates'] ?? [];
        $chartConfig    = $config['chart'] ?? [];
        $labelField     = $chartConfig['label_field'] ?? null;
        $valueField     = $chartConfig['value_field'] ?? null;
        $excludeEmpty   = $config['exclude_empty_labels'] ?? true; // default: exclude NULL/''
        $topNLimit      = isset($chartConfig['limit']) ? (int) $chartConfig['limit'] : null;
        $othersLabelRaw = $chartConfig['others_label'] ?? null;
        // Resolve others_label by current locale when it is a {ru,en} array.
        // Falls back to raw string for backward-compat with flat-string configs.
        $othersLabel = $this->resolveLocalizedString($othersLabelRaw);

        // Parse and classify group fields; apply JOINs for dot-path fields.
        // $resolvedGroupFields maps each raw config field to the SQL expression
        // used in GROUP BY (bare field or alias string).
        // $dotPathAliasMap maps dot-path label_field → internal alias.
        // $temporalAliasMap maps temporal token → internal alias.
        $resolvedGroupFields = []; // raw_field → sql_alias (for GROUP BY reference)
        $dotPathAliasMap     = []; // "relation.column" → "relation__column"
        $temporalAliasMap    = []; // "field|granularity" → "field__granularity"
        // selectParts for non-JOIN, non-temporal group fields
        $selectParts         = [];

        foreach ($groupFields as $rawField) {
            if (!is_string($rawField)) {
                continue;
            }

            if (preg_match(self::TEMPORAL_TOKEN_REGEX, $rawField, $m)) {
                // Temporal token: "deal_date|month"
                $fieldName   = $m[1];
                $granularity = $m[2];
                $alias       = $this->applyTemporalSelect(
                    $query,
                    $instance->getTable(),
                    $fieldName,
                    $granularity,
                );
                if ($alias === null) {
                    continue; // safety: should not happen given regex, but guard anyway
                }
                $resolvedGroupFields[$rawField] = $alias;
                $temporalAliasMap[$rawField]    = $alias;

            } elseif (preg_match(self::RELATION_DOT_PATH_REGEX, $rawField, $m)) {
                // Dot-path: attempt relation JOIN.
                $relationName = $m[1];
                $columnName   = $m[2];
                $alias = $this->applyRelationJoin($query, $instance, $relationName, $columnName);
                if ($alias === null) {
                    Log::warning('WidgetDataService: relation group_by skipped', [
                        'widget_id' => $widget->id,
                        'field'     => $rawField,
                    ]);
                    continue;
                }
                $resolvedGroupFields[$rawField] = $alias;
                $dotPathAliasMap[$rawField]     = $alias;

            } elseif ($this->isSafeIdentifier($rawField)) {
                // Bare identifier — group directly on the primary table column.
                $resolvedGroupFields[$rawField] = $rawField;
                $selectParts[] = "`{$rawField}`";
            }
            // else: unsafe / malformed — skip silently.
        }

        if (empty($resolvedGroupFields)) {
            return $this->emptyPayload($periodFrom, $periodTo, $periodApplied, $unresolvedVars);
        }

        // SQL expressions for GROUP BY: for temporal and relation aliases use
        // the alias directly; for bare fields use the bare name.
        $groupSqlExprs = array_values($resolvedGroupFields);

        // Build aggregate SELECT parts.
        $aggregateAliases = [];

        foreach ($aggregates as $aggDef) {
            $fn    = strtolower(trim($aggDef['fn'] ?? 'count'));
            $field = $aggDef['field'] ?? null;
            $alias = $aggDef['as'] ?? ($field ?? 'aggregate');

            if (!in_array($fn, self::AGGREGATE_FUNCTIONS, true)) {
                continue;
            }

            if (!is_string($alias) || !$this->isSafeIdentifier($alias)) {
                continue;
            }

            if ($fn === 'count') {
                $selectParts[] = "COUNT(*) AS `{$alias}`";
            } else {
                if (!is_string($field) || !$this->isSafeIdentifier($field)) {
                    continue;
                }
                $fnUpper = strtoupper($fn);
                $primaryTable = $instance->getTable();
                $selectParts[] = "{$fnUpper}(`{$primaryTable}`.`{$field}`) AS `{$alias}`";
            }

            $aggregateAliases[] = $alias;
        }

        if (empty($aggregateAliases)) {
            return $this->emptyPayload($periodFrom, $periodTo, $periodApplied, $unresolvedVars);
        }

        if (!empty($selectParts)) {
            $query->selectRaw(implode(', ', $selectParts));
        }

        $query->groupBy($groupSqlExprs);

        // Determine combined alias map for ORDER BY resolution.
        $combinedAliasMap = array_merge($dotPathAliasMap, $temporalAliasMap);

        // Apply ORDER BY if declared in config; fall back to first group expression asc.
        $this->applyOrderBy($query, $config['order_by'] ?? [], $resolvedGroupFields, $combinedAliasMap, $aggregateAliases);

        // Cap the result (before top-N post-processing).
        $query->limit(self::MAX_ROWS_PER_WIDGET);

        // ------------------------------------------------------------------
        // 8. Execute query.
        // ------------------------------------------------------------------
        try {
            $rows = $query->get()->map(fn($row) => $row->toArray())->all();
        } catch (\Exception $e) {
            Log::warning('WidgetDataService: query failed', [
                'widget_id' => $widget->id,
                'error'     => $e->getMessage(),
            ]);
            return $this->emptyPayload($periodFrom, $periodTo, $periodApplied, $unresolvedVars);
        }

        // ------------------------------------------------------------------
        // 9. Resolve label key and value field for shaping.
        // ------------------------------------------------------------------
        // label_field may be a dot-path or temporal token → map to internal alias.
        $resolvedLabelKey = $labelField;
        if ($labelField && is_string($labelField)) {
            if (isset($dotPathAliasMap[$labelField])) {
                $resolvedLabelKey = $dotPathAliasMap[$labelField];
            } elseif (isset($temporalAliasMap[$labelField])) {
                $resolvedLabelKey = $temporalAliasMap[$labelField];
            }
        }

        $effectiveValueField = $valueField;
        if (!$effectiveValueField && !empty($aggregateAliases)) {
            $effectiveValueField = $aggregateAliases[0];
        }

        // ------------------------------------------------------------------
        // 10. Filter empty labels (E1).
        //     By default (exclude_empty_labels: true) rows where the label
        //     column is NULL or '' are removed from the result set.
        // ------------------------------------------------------------------
        if ($excludeEmpty && $resolvedLabelKey) {
            $rows = array_values(array_filter($rows, function ($row) use ($resolvedLabelKey) {
                $label = $row[$resolvedLabelKey] ?? null;
                return $label !== null && $label !== '';
            }));
        }

        // ------------------------------------------------------------------
        // 11. Build raw labels + data arrays from rows.
        // ------------------------------------------------------------------
        $labels = [];
        if ($resolvedLabelKey && !empty($rows) && array_key_exists($resolvedLabelKey, $rows[0])) {
            $labels = array_map(fn($row) => (string) ($row[$resolvedLabelKey] ?? ''), $rows);
        } elseif (!empty($groupSqlExprs)) {
            $firstGroupKey = $groupSqlExprs[0];
            $labels = array_map(fn($row) => (string) ($row[$firstGroupKey] ?? ''), $rows);
        }

        $data = [];
        if ($effectiveValueField) {
            $data = array_map(function ($row) use ($effectiveValueField) {
                $v = $row[$effectiveValueField] ?? 0;
                return is_numeric($v) ? (float) $v : 0;
            }, $rows);
        }

        // ------------------------------------------------------------------
        // 12. Top-N + «Другие» (N1).
        //     If chart.limit is set, keep the top-N entries by value (desc).
        //     Remaining entries are summed into an "others" entry when
        //     chart.others_label is provided.
        // ------------------------------------------------------------------
        $othersCount = 0;
        if ($topNLimit !== null && $topNLimit > 0 && count($labels) > $topNLimit) {
            [$labels, $data, $othersCount] = $this->applyTopN(
                $labels,
                $data,
                $topNLimit,
                is_string($othersLabel) && $othersLabel !== '' ? $othersLabel : null,
            );
        }

        // If chart.label is a {ru,en} object — pass it through as-is so the
        // frontend can resolve the correct locale via resolveSeriesLabel().
        // Falls back to a plain string or the value_field alias for backward-compat.
        $datasetLabel = $chartConfig['label'] ?? ($effectiveValueField ?? 'value');

        // ------------------------------------------------------------------
        // 13. Compose response.
        // ------------------------------------------------------------------
        $meta = [
            'period_from'    => $effectivePeriodFrom,
            'period_to'      => $effectivePeriodTo,
            'period_applied' => $periodApplied,
            'row_count'      => count($rows),
        ];

        if ($othersCount > 0) {
            $meta['others_count'] = $othersCount;
        }

        if (!empty($unresolvedVars)) {
            $meta['unresolved_vars'] = array_values($unresolvedVars);
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label' => $datasetLabel,
                    'data'  => $data,
                ],
            ],
            'meta' => $meta,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Emit DATE_FORMAT(`<table>`.`<field>`, '<mask>') AS `<alias>` into the
     * query SELECT and return the alias string.
     *
     * The DATE_FORMAT mask is taken exclusively from TEMPORAL_GRANULARITIES —
     * the granularity string from config is used only as a map key, never
     * interpolated into SQL.
     *
     * The alias format "<field>__<granularity>" is added to the SELECT via
     * addSelect(DB::raw(...)) so it coexists with other selectRaw() calls.
     *
     * GROUP BY will reference the alias directly (MySQL allows aliases in
     * GROUP BY; SQLite also accepts this for test compatibility).
     *
     * @param  Builder  $query         Eloquent query (mutated in place).
     * @param  string   $primaryTable  Primary table name (from getTable()).
     * @param  string   $fieldName     Date column name (already validated by regex).
     * @param  string   $granularity   One of: month|year|day|week (validated by regex).
     * @return string   The SELECT alias, e.g. "deal_date__month".
     */
    protected function applyTemporalSelect(
        Builder $query,
        string $primaryTable,
        string $fieldName,
        string $granularity,
    ): ?string {
        $mask  = self::TEMPORAL_GRANULARITIES[$granularity] ?? null;
        if ($mask === null) {
            return null; // should not happen given TEMPORAL_TOKEN_REGEX, guard anyway
        }

        $alias = "{$fieldName}__{$granularity}";

        // DATE_FORMAT is MySQL syntax. For SQLite (test env) we fall back to
        // strftime() via a runtime check — but rather than branching here we
        // use MySQL syntax unconditionally because:
        //   (a) Production MacroData is always MySQL.
        //   (b) Tests that exercise temporal group_by use a SQLite-compatible
        //       equivalent expressed through the stub model's connection.
        //   See WidgetDataTest for test strategy.
        $query->addSelect(DB::raw("DATE_FORMAT(`{$primaryTable}`.`{$fieldName}`, '{$mask}') AS `{$alias}`"));

        return $alias;
    }

    /**
     * Keep top-N entries by value (descending), collapsing the rest into an
     * "others" entry.
     *
     * The input $labels and $data arrays are parallel and equal-length.
     * The return value is a triple [$labels, $data, $othersCount]:
     *   - $labels and $data are reordered: top-N (original order preserved
     *     within them), then optionally the "others" entry appended last.
     *   - $othersCount is the number of entries that were collapsed.
     *
     * When $othersLabel is null the collapsed entries are simply discarded
     * (plain top-N truncation without the "Другие" bar).
     *
     * The "top-N" selection is by value descending; the original relative
     * order of the top-N entries is preserved (stable sort).
     *
     * @param  list<string>    $labels
     * @param  list<float>     $data
     * @param  int             $limit
     * @param  string|null     $othersLabel  null → no "others" entry added
     * @return array{list<string>, list<float>, int}
     */
    protected function applyTopN(array $labels, array $data, int $limit, ?string $othersLabel): array
    {
        $count = count($labels);

        if ($count <= $limit) {
            return [$labels, $data, 0];
        }

        // Build indexed pairs, sort descending by value, pick top-N indices.
        $indexed = array_map(fn($i) => ['i' => $i, 'v' => $data[$i] ?? 0.0], range(0, $count - 1));
        usort($indexed, fn($a, $b) => $b['v'] <=> $a['v']);

        // Indices of top-N entries (unsorted — we restore original order below).
        $topIndicesSet = array_flip(array_column(array_slice($indexed, 0, $limit), 'i'));

        $topLabels  = [];
        $topData    = [];
        $othersSum  = 0.0;
        $othersCount = 0;

        for ($i = 0; $i < $count; $i++) {
            if (isset($topIndicesSet[$i])) {
                $topLabels[] = $labels[$i];
                $topData[]   = $data[$i];
            } else {
                $othersSum  += (float) ($data[$i] ?? 0.0);
                $othersCount++;
            }
        }

        if ($othersLabel !== null && $othersCount > 0) {
            $topLabels[] = $othersLabel;
            $topData[]   = $othersSum;
        }

        return [$topLabels, $topData, $othersCount];
    }

    /**
     * Apply config where[] conditions to the query.
     *
     * Supported condition shapes (mirrors ReportDataService::applyGlobalWheres):
     *   ['type' => 'whereIn',    'field' => 'status',  'value' => [1,3]]
     *   ['type' => 'whereNotIn', 'field' => 'status',  'value' => [2]]
     *   ['type' => 'whereNull',  'field' => 'deleted_at']
     *   ['type' => 'whereNotNull','field'=> 'deal_id']
     *   ['type' => 'where',      'field' => 'type_id', 'operator' => '=', 'value' => 42]
     *
     * We intentionally do NOT support 'whereHas' here — the whereHas closure
     * pattern in ReportDataService requires Eloquent relation metadata that is
     * not loaded in this lightweight context.
     *
     * @param  string|null  $primaryTable  When provided, field references are
     *   qualified as "<primaryTable>.<field>" to avoid "ambiguous column" errors
     *   when relation JOINs have been added to the query.
     */
    protected function applyWheres(Builder $query, array $wheres, ?string $primaryTable = null): void
    {
        $allowedOperators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'like', 'in', 'not in'];

        foreach ($wheres as $condition) {
            $type      = $condition['type']  ?? 'whereNotNull';
            $field     = $condition['field'] ?? null;

            if (!$field || !is_string($field) || !$this->isSafeIdentifier($field)) {
                continue;
            }

            // Qualify field with primary table when given — prevents MySQL
            // "ambiguous column" errors once relation JOINs are part of the query.
            $qualifiedField = $primaryTable ? "{$primaryTable}.{$field}" : $field;

            switch ($type) {
                case 'whereIn':
                    if (isset($condition['value']) && is_array($condition['value'])) {
                        $query->whereIn($qualifiedField, $condition['value']);
                    }
                    break;

                case 'whereNotIn':
                    if (isset($condition['value']) && is_array($condition['value'])) {
                        $query->whereNotIn($qualifiedField, $condition['value']);
                    }
                    break;

                case 'whereNull':
                    $query->whereNull($qualifiedField);
                    break;

                case 'whereNotNull':
                    $query->whereNotNull($qualifiedField);
                    break;

                case 'where':
                    $op    = strtolower(trim($condition['operator'] ?? '='));
                    $value = $this->resolveDynamicValue($condition['value'] ?? null);
                    if (in_array($op, $allowedOperators, true)) {
                        $query->where($qualifiedField, $op, $value);
                    }
                    break;

                default:
                    // Unknown type — skip silently (no whereHas support here).
                    break;
            }
        }
    }

    /**
     * Apply ORDER BY from config.
     *
     * Allowed order_by.field values:
     *   - any raw config group_by field (bare identifier, dot-path, or temporal token)
     *   - any aggregate alias
     *
     * Dot-path and temporal token fields are ordered by their SELECT alias.
     * Temporal aliases are lexicographically sortable for month/year/day
     * granularities (e.g. "2026-01" < "2026-05"), so ASC = chronological.
     * Unknown or unsafe fields are silently skipped.
     *
     * Falls back to first resolved group expression ASC when no order_by declared.
     *
     * @param  array  $resolvedGroupFields  raw_field → sql_alias (group by mapping)
     * @param  array  $combinedAliasMap     dot-path + temporal raw_field → alias
     * @param  array  $aggregateAliases     list of aggregate alias strings
     */
    protected function applyOrderBy(
        Builder $query,
        array $orderBy,
        array $resolvedGroupFields,
        array $combinedAliasMap,
        array $aggregateAliases,
    ): void {
        $allowedRaw = array_merge(array_keys($resolvedGroupFields), $aggregateAliases);
        $applied    = false;

        foreach ($orderBy as $spec) {
            $field = $spec['field'] ?? null;
            $dir   = strtolower($spec['dir'] ?? 'asc');

            if (!$field || !in_array($field, $allowedRaw, true)) {
                continue;
            }

            if (!in_array($dir, ['asc', 'desc'], true)) {
                $dir = 'asc';
            }

            // Translate dot-path / temporal token to alias for ORDER BY.
            $sqlField = $combinedAliasMap[$field] ?? $resolvedGroupFields[$field] ?? $field;
            $query->orderBy($sqlField, $dir);
            $applied = true;
        }

        if (!$applied) {
            $first = array_values($resolvedGroupFields)[0] ?? null;
            if ($first !== null) {
                $query->orderBy($first, 'asc');
            }
        }
    }

    /**
     * Add a LEFT JOIN for a single-hop relation group_by field and return the
     * SELECT alias used for this field in GROUP BY / ORDER BY / label resolution.
     *
     * The alias format is "<relation>__<column>" (double-underscore separator).
     * The SELECT expression "<join_alias>.<column> AS <alias>" is pushed
     * directly onto the query's addSelect() so that it coexists with any
     * selectRaw() added later for aggregates.
     *
     * Security:
     *   - relationName and columnName are validated against SAFE_IDENT_REGEX
     *     before this method is called (the caller checked via RELATION_DOT_PATH_REGEX).
     *   - method_exists() verifies the relation exists on the model — no arbitrary
     *     method invocation from raw config strings.
     *   - Only BelongsTo and HasOne are accepted. HasMany would produce row
     *     duplication and corrupt aggregate counts.
     *   - Table names and key names come exclusively from Eloquent internals
     *     (getTable(), getForeignKeyName(), getOwnerKeyName(), getLocalKeyName()).
     *     No config values are interpolated into the JOIN clause.
     *
     * @param  Builder  $query        Eloquent query builder (mutated in place).
     * @param  \Illuminate\Database\Eloquent\Model  $modelInstance  Primary model instance.
     * @param  string   $relationName  Relation method name on primary model (e.g. "usersManager").
     * @param  string   $columnName    Leaf column on the related table (e.g. "users_name").
     * @return string|null  The SELECT alias (e.g. "usersManager__users_name"), or null on failure.
     */
    protected function applyRelationJoin(
        Builder $query,
        \Illuminate\Database\Eloquent\Model $modelInstance,
        string $relationName,
        string $columnName,
    ): ?string {
        // Verify the relation method exists on the model.
        if (!method_exists($modelInstance, $relationName)) {
            return null;
        }

        try {
            $relationObj  = $modelInstance->{$relationName}();
            $relatedModel = $relationObj->getRelated();
            $relatedTable = $relatedModel->getTable();
        } catch (\Throwable) {
            return null;
        }

        $primaryTable = $modelInstance->getTable();
        $joinAlias    = self::JOIN_ALIAS_PREFIX . $relationName;
        $selectAlias  = $relationName . '__' . $columnName;

        if ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            $fk       = $relationObj->getForeignKeyName();  // column on primary table
            $ownerKey = $relationObj->getOwnerKeyName();    // column on related table

            $query->leftJoin(
                "{$relatedTable} AS {$joinAlias}",
                "{$primaryTable}.{$fk}",
                '=',
                "{$joinAlias}.{$ownerKey}",
            );
        } elseif ($relationObj instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            $localKey = $relationObj->getLocalKeyName(); // column on primary table
            $fk       = $relationObj->getForeignKeyName(); // column on related table

            $query->leftJoin(
                "{$relatedTable} AS {$joinAlias}",
                "{$primaryTable}.{$localKey}",
                '=',
                "{$joinAlias}.{$fk}",
            );
        } else {
            // HasMany / BelongsToMany / MorphTo etc. — would produce duplicate rows.
            return null;
        }

        // Add the join column to SELECT as an alias.
        // addSelect() accumulates without wiping previous selects.
        $query->addSelect(\Illuminate\Support\Facades\DB::raw("`{$joinAlias}`.`{$columnName}` AS `{$selectAlias}`"));

        return $selectAlias;
    }

    /**
     * Resolve a model class by short name (e.g. 'EstateDeals').
     * Returns null when neither the bare name nor the MacroData namespace exists.
     */
    protected function resolveModelClass(string $modelClass): ?string
    {
        if (class_exists($modelClass)) {
            return $modelClass;
        }

        $fullClass = "App\\Models\\MacroData\\{$modelClass}";

        return class_exists($fullClass) ? $fullClass : null;
    }

    /**
     * Validate a bare identifier (no dots, no spaces, no quotes).
     * Matches the regex in DataProbeService and ExpressionSqlTranslator.
     */
    protected function isSafeIdentifier(string $identifier): bool
    {
        return (bool) preg_match(self::SAFE_IDENT_REGEX, $identifier);
    }

    /**
     * Resolve dynamic value placeholders ({today}, {start_of_month}, etc.).
     * Mirrors ReportDataService::resolveDynamicValue().
     */
    protected function resolveDynamicValue(mixed $value): mixed
    {
        if (!is_string($value) || !preg_match('/^\{([^}]+)\}$/', $value, $m)) {
            return $value;
        }

        return match ($m[1]) {
            'today'          => Carbon::today()->toDateString(),
            'now'            => Carbon::now()->toDateTimeString(),
            'start_of_month' => Carbon::now()->startOfMonth()->toDateString(),
            'end_of_month'   => Carbon::now()->endOfMonth()->toDateString(),
            'start_of_year'  => Carbon::now()->startOfYear()->toDateString(),
            'end_of_year'    => Carbon::now()->endOfYear()->toDateString(),
            'start_of_day'   => Carbon::today()->startOfDay()->toDateTimeString(),
            'end_of_day'     => Carbon::today()->endOfDay()->toDateTimeString(),
            'minus_30_days'  => Carbon::now()->subDays(30)->toDateString(),
            default          => $value,
        };
    }

    /**
     * Normalize a YYYY-MM string into [date-from (Y-m-d), date-to (Y-m-d)].
     * Returns null when the string is absent or malformed.
     */
    protected function parseYearMonth(?string $ym): ?array
    {
        if (!$ym) {
            return null;
        }

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            return null;
        }

        try {
            $dt = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
            return [$dt->toDateString(), $dt->copy()->endOfMonth()->toDateString()];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Resolve the effective [date_from (Y-m-d), date_to (Y-m-d)] pair for a
     * period filter, handling three input cases:
     *
     *   1. Both $periodFrom and $periodTo supplied → use them (inclusive range).
     *   2. Only $periodFrom supplied → single-month window (from = to).
     *   3. Neither supplied → apply default:
     *        - temporal widget (has temporal group_by field) → last 12 months
     *        - snapshot widget (no temporal group_by)        → current month
     *
     * Returns null when both params are null AND the config has no period_field
     * (caller skips the filter entirely in that case).
     *
     * @param  string|null  $periodFrom  YYYY-MM start (may also be a single ?period= value).
     * @param  string|null  $periodTo    YYYY-MM end.
     * @param  array        $config      Widget config (used to detect temporal group_by for defaults).
     * @return array|null   [Y-m-d from, Y-m-d to] or null (no filter to apply).
     */
    protected function normalizePeriodRange(
        ?string $periodFrom,
        ?string $periodTo,
        array $config = [],
    ): ?array {
        // Case 1 / 2: caller supplied at least $periodFrom.
        if ($periodFrom !== null) {
            $fromParsed = $this->parseYearMonth($periodFrom);
            if ($fromParsed === null) {
                return null; // malformed — skip filter
            }
            $effectiveFrom = $fromParsed[0];

            if ($periodTo !== null) {
                $toParsed = $this->parseYearMonth($periodTo);
                if ($toParsed === null) {
                    return null;
                }
                $effectiveTo = $toParsed[1];
            } else {
                // Single month: from = to (same month end-of-month).
                $effectiveTo = $fromParsed[1];
            }

            return [$effectiveFrom, $effectiveTo];
        }

        // Case 3: no params supplied → apply default based on widget type.
        $isTemporalWidget = $this->hasTemporalGroupBy($config);

        if ($isTemporalWidget) {
            // Last 12 months: from = start of month 11 months ago → to = end of current month.
            $effectiveFrom = Carbon::now()->subMonths(11)->startOfMonth()->toDateString();
            $effectiveTo   = Carbon::now()->endOfMonth()->toDateString();
        } else {
            // Snapshot: current month only.
            $effectiveFrom = Carbon::now()->startOfMonth()->toDateString();
            $effectiveTo   = Carbon::now()->endOfMonth()->toDateString();
        }

        return [$effectiveFrom, $effectiveTo];
    }

    /**
     * Resolve a localized string value that may be either a plain string or a
     * {ru, en, ...} associative array. Returns the string for the current
     * application locale, falling back to 'ru', then 'en', then null.
     *
     * This enables graceful backward-compat: configs written before i18n (flat
     * string) continue to work alongside new {ru,en} objects without code changes.
     *
     * @param  mixed  $value  Plain string, ['ru' => '...', 'en' => '...'], or null.
     * @return string|null    Resolved string for current locale, or null when absent.
     */
    protected function resolveLocalizedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            $locale = app()->getLocale();
            if (isset($value[$locale]) && is_string($value[$locale]) && $value[$locale] !== '') {
                return $value[$locale];
            }
            // Fallback chain: ru → en → first non-empty value.
            foreach (['ru', 'en'] as $fallback) {
                if (isset($value[$fallback]) && is_string($value[$fallback]) && $value[$fallback] !== '') {
                    return $value[$fallback];
                }
            }
            foreach ($value as $v) {
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * Returns true when at least one group_by.fields entry is a temporal token
     * (e.g. "deal_date|month"). Used to pick the right default period.
     */
    protected function hasTemporalGroupBy(array $config): bool
    {
        $groupFields = $config['group_by']['fields'] ?? [];
        foreach ($groupFields as $field) {
            if (is_string($field) && preg_match(self::TEMPORAL_TOKEN_REGEX, $field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return an empty chart payload (all fields present but empty/false).
     * Used on connection failure, missing config, or unsupported configuration.
     *
     * @param  string|null  $periodFrom      Raw period_from param (for meta).
     * @param  string|null  $periodTo        Raw period_to param (for meta).
     * @param  bool         $periodApplied   Whether period was applied before the fallback.
     * @param  array        $unresolvedVars  Unresolved $company_var keys for meta.
     */
    protected function emptyPayload(
        ?string $periodFrom,
        ?string $periodTo   = null,
        bool    $periodApplied = false,
        array   $unresolvedVars = [],
    ): array {
        $meta = [
            'period_from'    => $periodFrom,
            'period_to'      => $periodTo,
            'period_applied' => $periodApplied,
            'row_count'      => 0,
        ];

        if (!empty($unresolvedVars)) {
            $meta['unresolved_vars'] = array_values($unresolvedVars);
        }

        return [
            'labels'   => [],
            'datasets' => [],
            'meta'     => $meta,
        ];
    }
}
