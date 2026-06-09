<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use Illuminate\Database\Eloquent\Builder;

class DataProbeService
{
    /**
     * Identifier regex — same shape as ExpressionSqlTranslator: simple
     * [a-zA-Z_][a-zA-Z0-9_]* (no dots, no spaces, no quotes). Used to
     * guard field names that flow into selectRaw / groupBy() / orderBy().
     */
    protected const SAFE_IDENT_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Aggregate functions whitelisted for query() — anything outside this
     * list is rejected before reaching SQL. Lowercase keys match what AI
     * is instructed to send in the tool argument.
     */
    protected const AGGREGATE_FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * PII deny-list — field names that AI is forbidden to select, group by,
     * order by, or aggregate over via query_data. Matching is case-insensitive
     * exact match on the bare identifier (no relation prefix). Substring
     * matches like "user_password_hash" would also trip because we check the
     * stripped lowercase token.
     *
     * Reason: AI must never surface raw PII to the chat output. If the user
     * legitimately needs to view individual records, they should open the
     * report constructor or contact admin.
     */
    protected const PII_DENY_LIST = [
        'password',
        'password_hash',
        'remember_token',
        'api_token',
        'email',
        'phone',
        'phone_number',
        'mobile',
        'passport',
        'passport_number',
        'passport_series',
        'iin',
        'bin',
        'inn',
        'snils',
        'ssn',
        'tax_id',
        'card_number',
        'credit_card',
        'cvv',
        'pin',
        'birth_date',
        'birthday',
        'date_of_birth',
    ];

    /**
     * Hard cap on result rows returned by query() when group_by is used.
     * AI may pass smaller limits, but anything above this is clamped down.
     */
    public const MAX_LIMIT = 200;
    public const DEFAULT_LIMIT = 50;

    /**
     * Probe response size caps. probe() output is fed straight into the AI
     * context, and on report_generation the system prompt is already ~250 KB
     * (REPORTS_GUIDE.md). A wide MacroData table with 40+ columns × 5 sample
     * rows + long text/JSON values used to push the prompt past GLM-5.1's input
     * limit (HTTP 400, code 1261 "Prompt exceeds max length"). We trim the
     * sample to a handful of rows and truncate long scalar values so the AI
     * still sees the SHAPE of the data (field names, types, example values)
     * without paying for full row dumps. The AI only needs to confirm field
     * names exist and eyeball a couple of values — not read the dataset.
     */
    protected const PROBE_SAMPLE_ROWS = 3;
    protected const PROBE_MAX_STRING_LEN = 120;

    /**
     * Custom-attribute (EAV) probe caps.
     *
     * MACRO stores user-defined "custom columns" (balcony / terrace area,
     * nationality, condition, ...) outside the main tables, in EAV side-tables:
     *   - `estate_attributes`   — admin-defined custom attributes, keyed by an
     *                             int `attr_id` (human title in
     *                             `estate_attributes_names.attr_title`), scoped
     *                             by `entity` ('estate_sell' | 'contacts' |
     *                             'estate_deal' | 'estate_buy' | 'promos').
     *   - `estate_sells_attr`   — built-in per-unit attributes, keyed by a
     *                             string `attr_name` (estate_area_balcony,
     *                             estate_area_terrace, estate_area_living, ...).
     *
     * A flat probe of EstateSells / EstateDeals never shows these — they live in
     * a different table. probeCustomAttributes() enumerates them so the AI can
     * SEE which custom fields exist for a client before declaring a requested
     * column "unavailable". We cap the catalogue to keep the AI context small.
     */
    public const CUSTOM_ATTR_MAX = 60;
    protected const CUSTOM_ATTR_SAMPLE_LEN = 60;

    /**
     * Allowed `entity` values for custom-attribute probing. Mirrors the values
     * MACRO writes into estate_attributes.entity / estate_attributes_names.entity.
     * Anything outside this list is rejected (defence-in-depth: the value flows
     * into a parameterised WHERE, but we keep the surface tight anyway).
     */
    protected const CUSTOM_ATTR_ENTITIES = [
        'estate_sell',
        'estate_deal',
        'estate_buy',
        'contacts',
        'promos',
    ];

    public function __construct(
        protected ConnectionService $connectionService,
    ) {}

    /**
     * Enumerate the custom / EAV attributes available for a given entity.
     *
     * This is the "always check custom columns" probe. When a user asks for a
     * report column that has no direct table field (balcony area, terrace,
     * nationality, condition, custom status, ...) the AI MUST call this before
     * concluding the data doesn't exist — those values live in the EAV
     * side-tables, not in the model's own columns.
     *
     * Returns two catalogues:
     *   - `custom_attributes` — from `estate_attributes` (admin-defined), each
     *     `{attr_id, title, entity, attr_type, fill_count, sample_value}`.
     *     `title` is resolved from `estate_attributes_names.attr_title`.
     *   - `builtin_sell_attributes` — from `estate_sells_attr` (only meaningful
     *     for entity='estate_sell'), each `{attr_name, attr_type, fill_count,
     *     sample_value}`. These map cleanly to a `relation_aggregate` over the
     *     `EstateSells.estateSellsAttrs` HasMany (see REPORTS_GUIDE EAV recipe).
     *
     * Read-only: SELECT / GROUP BY only, no writes to MacroData. All identifiers
     * are static (no AI-supplied column names reach SQL); `$entity` is validated
     * against CUSTOM_ATTR_ENTITIES and bound as a parameter.
     *
     * @param  Company  $company  Company context for MacroData connection
     * @param  string  $entity    One of CUSTOM_ATTR_ENTITIES (default estate_sell)
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException For an unknown entity value
     */
    public function probeCustomAttributes(Company $company, string $entity = 'estate_sell'): array
    {
        $entity = strtolower(trim($entity));
        if (!in_array($entity, self::CUSTOM_ATTR_ENTITIES, true)) {
            throw new \InvalidArgumentException(
                "Unknown entity '{$entity}'. Allowed: " . implode(', ', self::CUSTOM_ATTR_ENTITIES)
            );
        }

        $this->connectionService->connect($company);

        return [
            'entity'                  => $entity,
            'custom_attributes'       => $this->probeAdminCustomAttributes($entity),
            'builtin_sell_attributes' => $entity === 'estate_sell'
                ? $this->probeBuiltinSellAttributes()
                : [],
            'hint' => 'custom_attributes live in estate_attributes (key = attr_id, '
                . 'human title = attr_title). builtin_sell_attributes live in '
                . 'estate_sells_attr (key = attr_name) and can be surfaced as a '
                . 'report column via relation_aggregate over EstateSells.estateSellsAttrs '
                . '(HasMany) — see the EAV recipe in the report guide.',
        ];
    }

    /**
     * Admin-defined custom attributes from `estate_attributes`, joined to
     * `estate_attributes_names` for the human title, scoped by entity. One row
     * per distinct attr_id with a fill count and a sample value.
     *
     * @return list<array<string, mixed>>
     */
    protected function probeAdminCustomAttributes(string $entity): array
    {
        $connection = (new \App\Models\MacroData\EstateAttributes)->getConnection();

        $rows = $connection->table('estate_attributes as ea')
            ->leftJoin('estate_attributes_names as ean', 'ean.id', '=', 'ea.attr_id')
            ->where('ea.entity', $entity)
            ->whereNotNull('ea.attr_value')
            ->where('ea.attr_value', '!=', '')
            ->groupBy('ea.attr_id', 'ean.attr_title', 'ean.attr_type')
            ->selectRaw('ea.attr_id as attr_id')
            ->selectRaw('MAX(ean.attr_title) as title')
            ->selectRaw('MAX(ean.attr_type) as attr_type')
            ->selectRaw('COUNT(*) as fill_count')
            ->selectRaw('MAX(ea.attr_value) as sample_value')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::CUSTOM_ATTR_MAX)
            ->get();

        return $rows->map(fn ($r) => [
            'attr_id'      => (int) $r->attr_id,
            'title'        => $r->title,
            'attr_type'    => $r->attr_type,
            'fill_count'   => (int) $r->fill_count,
            'sample_value' => $this->clampSample($r->sample_value),
        ])->all();
    }

    /**
     * Built-in per-unit attributes from `estate_sells_attr`. One row per distinct
     * attr_name with a fill count and a sample value.
     *
     * @return list<array<string, mixed>>
     */
    protected function probeBuiltinSellAttributes(): array
    {
        $connection = (new \App\Models\MacroData\EstateSellsAttr)->getConnection();

        $rows = $connection->table('estate_sells_attr')
            ->whereNotNull('attr_value')
            ->where('attr_value', '!=', '')
            ->groupBy('attr_name', 'attr_table')
            ->selectRaw('attr_name as attr_name')
            ->selectRaw('MAX(attr_table) as attr_type')
            ->selectRaw('COUNT(*) as fill_count')
            ->selectRaw('MAX(attr_value) as sample_value')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::CUSTOM_ATTR_MAX)
            ->get();

        return $rows->map(fn ($r) => [
            'attr_name'    => $r->attr_name,
            'attr_type'    => $r->attr_type,
            'fill_count'   => (int) $r->fill_count,
            'sample_value' => $this->clampSample($r->sample_value),
        ])->all();
    }

    /**
     * Clamp an EAV sample value to keep the catalogue small in the AI context.
     */
    protected function clampSample(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::CUSTOM_ATTR_SAMPLE_LEN) {
            return mb_substr($value, 0, self::CUSTOM_ATTR_SAMPLE_LEN) . '…';
        }

        return $value;
    }

    /**
     * Get a sample of rows and total count for a MacroData model.
     */
    public function probe(Company $company, string $modelClass, array $fields = [], array $relations = []): array
    {
        $this->connectionService->connect($company);

        $fullClass = $this->resolveModelClass($modelClass);
        $instance = new $fullClass;
        $query = $instance->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        if (!empty($fields)) {
            $query->select($fields);
        }

        $rowCount = $instance->newQuery()->count();
        $sampleRows = $query->limit(self::PROBE_SAMPLE_ROWS)->get()->toArray();

        // Truncate long scalar values so a wide table with big text / serialized
        // JSON columns doesn't blow the AI context window. The AI only needs to
        // see field names + an example value shape, not full payloads.
        $sampleRows = array_map(fn ($row) => $this->truncateRowValues($row), $sampleRows);

        return [
            'model' => $modelClass,
            'row_count' => $rowCount,
            'sample_rows' => $sampleRows,
        ];
    }

    /**
     * Recursively truncate long string values in a probe sample row. Keeps the
     * structure (keys, nesting from eager-loaded relations) intact but clamps
     * any scalar string longer than PROBE_MAX_STRING_LEN, appending an ellipsis
     * so the AI can tell the value was cut. Non-string scalars (int/float/bool/
     * null) pass through unchanged. Arrays (nested relations) recurse.
     *
     * @param  array<mixed>  $row
     * @return array<mixed>
     */
    protected function truncateRowValues(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = $this->truncateRowValues($value);
            } elseif (is_string($value) && mb_strlen($value) > self::PROBE_MAX_STRING_LEN) {
                $row[$key] = mb_substr($value, 0, self::PROBE_MAX_STRING_LEN) . '…';
            }
        }

        return $row;
    }

    /**
     * Get statistics for a single field: min, max, avg, count, distinct count.
     */
    public function getFieldStats(Company $company, string $modelClass, string $field): array
    {
        $this->connectionService->connect($company);

        $fullClass = $this->resolveModelClass($modelClass);
        $instance = new $fullClass;

        return [
            'field' => $field,
            'min' => $instance->newQuery()->min($field),
            'max' => $instance->newQuery()->max($field),
            'avg' => $instance->newQuery()->avg($field),
            'count' => $instance->newQuery()->count(),
            'distinct' => $instance->newQuery()->distinct()->count($field),
        ];
    }

    /**
     * Get distinct values for a field (up to 30).
     */
    public function getDistinctValues(Company $company, string $modelClass, string $field): array
    {
        $this->connectionService->connect($company);

        $fullClass = $this->resolveModelClass($modelClass);
        $instance = new $fullClass;

        $values = $instance->newQuery()
            ->select($field)
            ->distinct()
            ->limit(30)
            ->pluck($field)
            ->filter()
            ->values()
            ->toArray();

        return [
            'field' => $field,
            'values' => $values,
        ];
    }

    /**
     * Execute a filtered query with aggregation, optionally grouped.
     *
     * Two modes:
     *   - No group_by: returns a single aggregate value (count/sum/avg/min/max).
     *   - With group_by: returns array of rows, each containing the group
     *     fields + 'aggregate' key with the per-group value, optionally
     *     ordered and limited (max 200 rows, default 50).
     *
     * Safety:
     *   - All field/group/order identifiers pass SAFE_IDENT_REGEX before
     *     touching SQL. Anything that fails throws InvalidArgumentException
     *     with a message the LLM can read and retry from.
     *   - All field names are checked against PII_DENY_LIST before they
     *     reach the database. AI must never surface raw PII.
     *   - Field names are double-quoted with backticks for raw SQL fragments
     *     (selectRaw); Eloquent QB handles quoting for groupBy/orderBy.
     *
     * @param  Company  $company  Company context for MacroData connection
     * @param  string  $modelClass  Model name (e.g. 'EstateDeals')
     * @param  string  $aggregate  Aggregation type: count, sum, avg, min, max
     * @param  string|null  $field  Field to aggregate (required for sum/avg/min/max)
     * @param  array<int, array{field:string,operator:string,value:mixed}>  $filters
     *     Where conditions. Supported operators: =, !=, >, <, >=, <=, like, in, not in.
     *     For in/not in, value should be an array.
     * @param  string[]  $groupBy  Group fields (each must be a simple identifier).
     *     When non-empty, return shape changes to array of rows.
     * @param  array<int, array{field:string,dir?:string}>  $orderBy  Order spec.
     *     Allowed field names: any of the group_by fields OR the literal
     *     string 'aggregate' (to sort by the aggregate value).
     * @param  int|null  $limit  Row cap when grouped (default 50, hard cap 200).
     *     Ignored when groupBy is empty.
     * @return array<string, mixed> When ungrouped: scalar result + metadata.
     *     When grouped: rows[] + metadata.
     *
     * @throws \InvalidArgumentException For invalid field names, denied PII, or unsupported aggregate
     */
    public function query(
        Company $company,
        string $modelClass,
        string $aggregate,
        ?string $field = null,
        array $filters = [],
        array $groupBy = [],
        array $orderBy = [],
        ?int $limit = null,
    ): array {
        $aggregate = strtolower(trim($aggregate));
        if (!in_array($aggregate, self::AGGREGATE_FUNCTIONS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported aggregation: {$aggregate}. Allowed: " . implode(', ', self::AGGREGATE_FUNCTIONS)
            );
        }

        if ($aggregate !== 'count' && !$field) {
            throw new \InvalidArgumentException("Field is required for {$aggregate} aggregation");
        }

        // Validate aggregate field if present. We allow null only for count
        // (already handled above). PII check applies to anything except *.
        if ($field !== null && $field !== '*') {
            $this->assertSafeIdentifier($field, 'aggregate field');
            $this->assertNotPii($field, 'aggregate field');
        }

        // Validate group_by fields
        foreach ($groupBy as $g) {
            $this->assertSafeIdentifier($g, 'group_by field');
            $this->assertNotPii($g, 'group_by field');
        }

        // Validate order_by fields. Allowed fields: any in groupBy OR 'aggregate'.
        $allowedOrderFields = array_merge($groupBy, ['aggregate']);
        $normalizedOrderBy = [];
        foreach ($orderBy as $o) {
            $orderField = $o['field'] ?? null;
            $orderDir = strtolower($o['dir'] ?? 'asc');

            if (!$orderField) {
                continue;
            }

            if (!in_array($orderField, $allowedOrderFields, true)) {
                throw new \InvalidArgumentException(
                    "order_by field '{$orderField}' must be one of group_by fields or 'aggregate'."
                );
            }

            if (!in_array($orderDir, ['asc', 'desc'], true)) {
                throw new \InvalidArgumentException(
                    "order_by direction '{$orderDir}' is invalid. Use 'asc' or 'desc'."
                );
            }

            $normalizedOrderBy[] = ['field' => $orderField, 'dir' => $orderDir];
        }

        // Validate filter fields (loose: only the field name, value safety
        // is delegated to Eloquent QB which uses parameterised binds).
        foreach ($filters as $condition) {
            $filterField = $condition['field'] ?? null;
            if (!$filterField) {
                continue;
            }
            // Filter field allows dot-notation for relations? No, ReportTool
            // historically passes only bare column names. Keep it strict.
            $this->assertSafeIdentifier($filterField, 'filter field');
            $this->assertNotPii($filterField, 'filter field');
        }

        $this->connectionService->connect($company);

        $fullClass = $this->resolveModelClass($modelClass);
        $instance = new $fullClass;
        $query = $instance->newQuery();

        $this->applyFilters($query, $filters);

        // ---------------------------------------------------------------
        // Branch 1 — Ungrouped (legacy behaviour): scalar aggregate.
        // ---------------------------------------------------------------
        if (empty($groupBy)) {
            $result = match ($aggregate) {
                'count' => $query->count(),
                'sum' => $query->sum($field),
                'avg' => $query->avg($field),
                'min' => $query->min($field),
                'max' => $query->max($field),
            };

            return [
                'model' => $modelClass,
                'aggregate' => $aggregate,
                'field' => $field,
                'result' => $result,
                'filters_applied' => $filters,
            ];
        }

        // ---------------------------------------------------------------
        // Branch 2 — Grouped: selectRaw + groupBy(). Returns rows array.
        // ---------------------------------------------------------------
        $aggregateSql = $aggregate === 'count'
            ? 'COUNT(*) AS `aggregate`'
            : sprintf('%s(`%s`) AS `aggregate`', strtoupper($aggregate), $field);

        $selectCols = array_map(fn($g) => "`{$g}`", $groupBy);
        $selectRawCols = implode(', ', $selectCols) . ', ' . $aggregateSql;

        $query->selectRaw($selectRawCols);
        $query->groupBy($groupBy);

        // Apply ordering. 'aggregate' is the alias we just defined; bare
        // group fields are safe identifiers (already validated).
        foreach ($normalizedOrderBy as $o) {
            $query->orderBy($o['field'], $o['dir']);
        }

        // Clamp limit to safe bounds.
        $effectiveLimit = $limit === null ? self::DEFAULT_LIMIT : max(1, min((int) $limit, self::MAX_LIMIT));
        $query->limit($effectiveLimit);

        $rows = $query->get()->map(function ($row) {
            // Eloquent returns model instances; coerce to array. Numeric
            // aggregate value is preserved as-is (PHP int/float/string per
            // driver). Frontend / AI gets whatever MySQL hands back.
            return $row->toArray();
        })->all();

        return [
            'model' => $modelClass,
            'aggregate' => $aggregate,
            'field' => $field,
            'group_by' => $groupBy,
            'order_by' => $normalizedOrderBy,
            'limit' => $effectiveLimit,
            'rows' => $rows,
            'row_count' => count($rows),
            'filters_applied' => $filters,
        ];
    }

    /**
     * Apply filter conditions to an Eloquent builder.
     *
     * Operators mirror legacy behaviour. Field names are pre-validated
     * by caller; values flow through QB parameterised binds.
     *
     * @param  Builder  $query
     * @param  array<int, array{field:string,operator:string,value:mixed}>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $condition) {
            $filterField = $condition['field'] ?? null;
            $operator = strtolower($condition['operator'] ?? '=');
            $value = $condition['value'] ?? null;

            if (!$filterField) {
                continue;
            }

            switch ($operator) {
                case 'in':
                    $query->whereIn($filterField, (array) $value);
                    break;
                case 'not in':
                    $query->whereNotIn($filterField, (array) $value);
                    break;
                case 'like':
                    $query->where($filterField, 'like', "%{$value}%");
                    break;
                default:
                    $query->where($filterField, $operator, $value);
                    break;
            }
        }
    }

    /**
     * Validate that a string is a safe SQL identifier (no dots, no quotes,
     * no spaces, starts with letter/underscore).
     *
     * @throws \InvalidArgumentException
     */
    protected function assertSafeIdentifier(string $identifier, string $contextLabel): void
    {
        if (!preg_match(self::SAFE_IDENT_REGEX, $identifier)) {
            throw new \InvalidArgumentException(
                "Invalid {$contextLabel} '{$identifier}'. Must be a simple identifier "
                . "(letters, digits, underscores; starts with letter or underscore). "
                . "Dot-notation relations are not supported in query_data."
            );
        }
    }

    /**
     * Reject field names matching the PII deny-list.
     *
     * @throws \InvalidArgumentException
     */
    protected function assertNotPii(string $identifier, string $contextLabel): void
    {
        $lower = strtolower($identifier);
        if (in_array($lower, self::PII_DENY_LIST, true)) {
            throw new \InvalidArgumentException(
                "Access denied: {$contextLabel} '{$identifier}' is PII and cannot be queried via query_data. "
                . "Use probe_data for non-PII fields, or open the report constructor with proper permissions."
            );
        }
    }

    protected function resolveModelClass(string $modelClass): string
    {
        if (class_exists($modelClass)) {
            return $modelClass;
        }

        $fullClass = "App\\Models\\MacroData\\{$modelClass}";

        if (!class_exists($fullClass)) {
            throw new \InvalidArgumentException("MacroData model not found: {$modelClass}");
        }

        return $fullClass;
    }
}
