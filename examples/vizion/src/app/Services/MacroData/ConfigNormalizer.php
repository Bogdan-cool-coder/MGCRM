<?php

namespace App\Services\MacroData;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

/**
 * Normalizes AI-generated report configs.
 *
 * AI sometimes produces snake_case model names and relation segments instead of
 * the canonical PascalCase / camelCase forms. This service:
 *   1. Builds a canonical map of {snake => Canonical} for models and relations
 *      once per process (cached indefinitely until macrodata:generate-relations
 *      or macrodata:rename-models clears the cache).
 *   2. Walks the known config paths and normalises each segment.
 *   3. Returns either a clean config (ok=true, changes=[...]) or a structured
 *      error (ok=false, errors=[...]) so ReportTool can feed it back to the AI.
 *
 * Covered config paths:
 *   - primary_model
 *   - columns[i].field
 *   - columns[i].extra_relations[j]
 *   - sort.default.field
 *   - totals[i]  (string entries)
 *   - where[i].relation  (whereHas)
 *   - filters[i].field
 *
 * NB: the legacy top-level `chart` key is no longer normalised. It was removed
 * from the report-config contract along with the dashboard-on-report
 * visualisation; a report is now a dry table.
 */
class ConfigNormalizer
{
    /** Cache key for the canonical model/relation map */
    protected const CACHE_KEY = 'macrodata.canonical_map';

    /** Namespace where MacroData models live */
    protected const MODELS_NAMESPACE = 'App\\Models\\MacroData\\';

    /** Filesystem path to scan for model files */
    protected const MODELS_PATH = 'app/Models/MacroData';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Normalise a report config array.
     *
     * @param  array $config  Raw config from AI tool call
     * @return array{ok: bool, config?: array, changes: list<array>, errors?: list<array>}
     */
    public function normalize(array $config): array
    {
        $map     = $this->getCanonicalMap();
        $changes = [];
        $errors  = [];

        // 1. primary_model
        $primaryRaw = $config['primary_model'] ?? null;
        if ($primaryRaw === null) {
            return ['ok' => false, 'changes' => [], 'errors' => [[
                'path'   => 'primary_model',
                'value'  => null,
                'reason' => 'primary_model is required',
            ]]];
        }

        [$canonical, $err] = $this->resolveModel($primaryRaw, $map);
        if ($err) {
            return ['ok' => false, 'changes' => [], 'errors' => [[
                'path'   => 'primary_model',
                'value'  => $primaryRaw,
                'reason' => $err,
            ]]];
        }
        if ($canonical !== $primaryRaw) {
            $changes[] = ['path' => 'primary_model', 'from' => $primaryRaw, 'to' => $canonical];
            $config['primary_model'] = $canonical;
        }

        $primaryModel = $config['primary_model'];

        // 2. columns[i].field  and  columns[i].extra_relations[j]
        foreach (($config['columns'] ?? []) as $i => $column) {
            if (isset($column['field'])) {
                [$normalized, $colChanges, $colErrors] = $this->normalizePath(
                    $column['field'],
                    $primaryModel,
                    "columns.{$i}.field",
                    $map,
                    isRelationOnlyPath: false,
                );
                $config['columns'][$i]['field'] = $normalized;
                $changes = array_merge($changes, $colChanges);
                $errors  = array_merge($errors, $colErrors);
            }

            foreach (($column['extra_relations'] ?? []) as $j => $rel) {
                [$normalized, $relChanges, $relErrors] = $this->normalizePath(
                    $rel,
                    $primaryModel,
                    "columns.{$i}.extra_relations.{$j}",
                    $map,
                    isRelationOnlyPath: true,
                );
                $config['columns'][$i]['extra_relations'][$j] = $normalized;
                $changes = array_merge($changes, $relChanges);
                $errors  = array_merge($errors, $relErrors);
            }
        }

        // 3. (removed) chart.x / chart.y normalisation — `chart` is deprecated.
        //    ReportTool rejects configs containing the key before they reach
        //    the normaliser, so this section is intentionally empty.

        // 5. sort.default.field  (usually a direct DB field, skip normalisation)
        // Same reasoning: last segment is always a DB column; relation-prefix
        // sorting is currently skipped by ReportDataService anyway.

        // 6. totals[i] — may be string key or associative (field => aggregation)
        if (!empty($config['totals'])) {
            $normalizedTotals = [];
            foreach ($config['totals'] as $key => $value) {
                if (is_int($key)) {
                    // Simple list: ['deal_sum', 'estateDeals.deal_sum']
                    [$normalized, $c, $e] = $this->normalizePath(
                        (string) $value,
                        $primaryModel,
                        "totals.{$key}",
                        $map,
                        isRelationOnlyPath: false,
                    );
                    $normalizedTotals[] = $normalized;
                    $changes = array_merge($changes, $c);
                    $errors  = array_merge($errors, $e);
                } else {
                    // Associative: ['estateDeals.deal_sum' => 'sum']
                    [$normalized, $c, $e] = $this->normalizePath(
                        (string) $key,
                        $primaryModel,
                        "totals.{$key}",
                        $map,
                        isRelationOnlyPath: false,
                    );
                    $normalizedTotals[$normalized] = $value;
                    $changes = array_merge($changes, $c);
                    $errors  = array_merge($errors, $e);
                }
            }
            $config['totals'] = $normalizedTotals;
        }

        // 7. where[i].relation  (whereHas only, relation chain without leaf field)
        foreach (($config['where'] ?? []) as $i => $condition) {
            if (($condition['type'] ?? '') === 'whereHas' && isset($condition['relation'])) {
                [$normalized, $c, $e] = $this->normalizePath(
                    $condition['relation'],
                    $primaryModel,
                    "where.{$i}.relation",
                    $map,
                    isRelationOnlyPath: true,
                );
                $config['where'][$i]['relation'] = $normalized;
                $changes = array_merge($changes, $c);
                $errors  = array_merge($errors, $e);
            }
        }

        // 8. filters[i].field  (relation chain + leaf field)
        foreach (($config['filters'] ?? []) as $i => $filter) {
            if (isset($filter['field'])) {
                [$normalized, $c, $e] = $this->normalizePath(
                    $filter['field'],
                    $primaryModel,
                    "filters.{$i}.field",
                    $map,
                    isRelationOnlyPath: false,
                );
                $config['filters'][$i]['field'] = $normalized;
                $changes = array_merge($changes, $c);
                $errors  = array_merge($errors, $e);
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'changes' => $changes, 'errors' => $errors];
        }

        return ['ok' => true, 'config' => $config, 'changes' => $changes];
    }

    /**
     * Flush the canonical map cache.
     * Call this after macrodata:rename-models or macrodata:generate-relations.
     */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise a dot-separated path.
     *
     * If isRelationOnlyPath=false:  all segments except the last are relations,
     *                               the last segment is a DB column (not touched).
     * If isRelationOnlyPath=true:   every segment is a relation name.
     *
     * @return array{0: string, 1: list<array>, 2: list<array>}
     *         [normalizedPath, changes, errors]
     */
    protected function normalizePath(
        string $path,
        string $primaryModel,
        string $configPath,
        array  $map,
        bool   $isRelationOnlyPath,
    ): array {
        if (!str_contains($path, '.')) {
            // No dot — either a plain DB column (isRelationOnlyPath=false) or a
            // single-segment relation name (isRelationOnlyPath=true).
            if ($isRelationOnlyPath) {
                [$canonical, $err] = $this->resolveRelation($path, $primaryModel, $map);
                if ($err) {
                    return [$path, [], [['path' => $configPath, 'value' => $path, 'reason' => $err]]];
                }
                $changes = $canonical !== $path
                    ? [['path' => $configPath, 'from' => $path, 'to' => $canonical]]
                    : [];
                return [$canonical, $changes, []];
            }
            // Plain DB field — nothing to normalise.
            return [$path, [], []];
        }

        $segments       = explode('.', $path);
        $leafField      = $isRelationOnlyPath ? null : array_pop($segments);
        $currentModel   = $primaryModel;
        $normalizedSegs = [];
        $changes        = [];
        $errors         = [];

        foreach ($segments as $idx => $segment) {
            [$canonical, $err] = $this->resolveRelation($segment, $currentModel, $map);
            if ($err) {
                $errors[] = ['path' => $configPath, 'value' => $path, 'reason' => $err];
                // Abort further resolution for this path
                return [$path, $changes, $errors];
            }

            if ($canonical !== $segment) {
                $changes[] = [
                    'path'    => $configPath,
                    'segment' => $segment,
                    'from'    => $segment,
                    'to'      => $canonical,
                ];
            }
            $normalizedSegs[] = $canonical;

            // Advance current model along the relation
            $related = $this->getRelatedModel($currentModel, $canonical, $map);
            if ($related === null) {
                // We resolved the canonical name but can't follow the chain further.
                // This is non-fatal: the remaining segments will still be processed
                // with a null current model (they'll pass through as-is).
                $currentModel = null;
            } else {
                $currentModel = $related;
            }
        }

        // Re-attach the leaf DB field
        if ($leafField !== null) {
            $normalizedSegs[] = $leafField;
        }

        return [implode('.', $normalizedSegs), $changes, $errors];
    }

    /**
     * Resolve a model name to its canonical PascalCase form.
     *
     * Accepts: PascalCase (identity), snake_case.
     *
     * @return array{0: string, 1: string|null}  [canonical, error|null]
     */
    protected function resolveModel(string $name, array $map): array
    {
        // Already canonical
        if (isset($map['models'][$name])) {
            return [$map['models'][$name], null];
        }

        // Try snake_case lookup
        $snake = Str::snake($name);
        if (isset($map['models'][$snake])) {
            return [$map['models'][$snake], null];
        }

        // Levenshtein suggestions
        $suggestions = $this->suggest($name, array_keys($map['models']));
        $hint = empty($suggestions)
            ? ''
            : ' Did you mean: ' . implode(', ', $suggestions) . '?';

        return ['', "Unknown model \"{$name}\".{$hint}"];
    }

    /**
     * Resolve a relation segment to its canonical camelCase form.
     *
     * @return array{0: string, 1: string|null}  [canonical, error|null]
     */
    protected function resolveRelation(string $segment, ?string $currentModel, array $map): array
    {
        if ($currentModel === null) {
            // We lost track of the model chain — pass through without error
            return [$segment, null];
        }

        $relMap = $map['relations'][$currentModel] ?? [];

        // Exact match (already canonical)
        if (isset($relMap[$segment])) {
            return [$relMap[$segment], null];
        }

        // snake_case → camel lookup
        $camel = Str::camel($segment);
        if (isset($relMap[$camel])) {
            return [$relMap[$camel], null];
        }

        // Levenshtein suggestions among canonical relation names
        $canonicals  = array_values(array_unique(array_values($relMap)));
        $suggestions = $this->suggest($segment, $canonicals);
        $hint = empty($suggestions)
            ? " No relations found on model {$currentModel}."
            : " Did you mean: " . implode(', ', $suggestions) . " (on model {$currentModel})?";

        return ['', "Unknown relation \"{$segment}\" on model \"{$currentModel}\".{$hint}"];
    }

    /**
     * Get the related model class name for a resolved relation.
     *
     * @return string|null  Short class name (PascalCase) or null if unknown.
     */
    protected function getRelatedModel(string $currentModel, string $canonicalRelation, array $map): ?string
    {
        return $map['related'][$currentModel][$canonicalRelation] ?? null;
    }

    // -------------------------------------------------------------------------
    // Canonical map builder
    // -------------------------------------------------------------------------

    /**
     * Get (or build) the canonical map from cache.
     *
     * Structure:
     * ```
     * [
     *   'models'    => ['estate_deals' => 'EstateDeals', 'EstateDeals' => 'EstateDeals', ...],
     *   'relations' => ['EstateDeals' => ['estate_sells' => 'estateSells', 'estateSells' => 'estateSells', ...]],
     *   'related'   => ['EstateDeals' => ['estateSells' => 'EstateSells', ...]],
     * ]
     * ```
     */
    public function getCanonicalMap(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => $this->buildCanonicalMap());
    }

    /**
     * Build the canonical map by reflecting over all MacroData model files.
     */
    public function buildCanonicalMap(): array
    {
        $modelsPath = base_path(self::MODELS_PATH);
        $files      = glob("{$modelsPath}/*.php") ?: [];

        $models    = [];
        $relations = [];
        $related   = [];

        foreach ($files as $file) {
            $className = self::MODELS_NAMESPACE . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (\ReflectionException) {
                continue;
            }

            // Must extend Eloquent Model
            if (!$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $shortName = $reflection->getShortName();

            // Model map: both snake and PascalCase → PascalCase
            $models[Str::snake($shortName)]  = $shortName;
            $models[$shortName]              = $shortName;

            // Relation map: scan public instance methods
            $relMap     = [];
            $relRelated = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip static, abstract, from base Model, from parent PHP classes
                if ($method->isStatic() || $method->isAbstract()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }

                $methodName = $method->getName();

                // Skip magic-style and lifecycle hooks
                if (str_starts_with($methodName, '__') || in_array($methodName, ['casts', 'boot', 'booted', 'resolveRouteBinding', 'resolveSoftDeletableRouteBinding'])) {
                    continue;
                }

                // Try to determine if this method returns a Relation
                $relatedClass = $this->probeRelation($className, $methodName);
                if ($relatedClass === null) {
                    continue;
                }

                // Relation entry: both snake_case and camelCase → camelCase canonical
                $snake = Str::snake($methodName);
                $relMap[$snake]      = $methodName;
                $relMap[$methodName] = $methodName;

                $relRelated[$methodName] = $relatedClass;
            }

            $relations[$shortName] = $relMap;
            $related[$shortName]   = $relRelated;
        }

        return compact('models', 'relations', 'related');
    }

    /**
     * Attempt to instantiate the model and call the method to inspect the
     * return value type.  Uses a try/catch to survive missing DB connection.
     *
     * Returns the short class name (PascalCase) of the related model, or null
     * if we cannot determine it.
     */
    protected function probeRelation(string $modelClass, string $methodName): ?string
    {
        // First attempt: use PHP return type hint (fastest, no DB needed)
        try {
            $reflection  = new ReflectionMethod($modelClass, $methodName);
            $returnType  = $reflection->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                $typeName = $returnType->getName();
                if (is_a($typeName, \Illuminate\Database\Eloquent\Relations\Relation::class, true)) {
                    // We know it IS a relation but need the related class.
                    // Fall through to instantiation below.
                } elseif ($typeName !== 'mixed' && $typeName !== '') {
                    // Declared return type is not a Relation subclass
                    return null;
                }
            }
        } catch (\ReflectionException) {
            return null;
        }

        // Second attempt: instantiate and call the method to get the Relation object.
        // We bypass Model::__construct to avoid DB boot, but class-level property
        // defaults ($connection, $table, $primaryKey) remain accessible.
        // The relation method only creates a BelongsTo/HasMany/etc. object — no query.
        try {
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();

            $result = $instance->{$methodName}();

            if (!($result instanceof \Illuminate\Database\Eloquent\Relations\Relation)) {
                return null;
            }

            $relatedInstance = $result->getRelated();
            $relatedClass    = get_class($relatedInstance);

            // Return only the short name (PascalCase, no namespace)
            return (new ReflectionClass($relatedClass))->getShortName();
        } catch (\Throwable) {
            // DB not available or method has side-effects — skip
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Levenshtein suggestions
    // -------------------------------------------------------------------------

    /**
     * Return up to 3 closest candidates from $pool for $input.
     *
     * @param  string   $input
     * @param  string[] $pool
     * @return string[]
     */
    protected function suggest(string $input, array $pool): array
    {
        if (empty($pool)) {
            return [];
        }

        $inputLower = strtolower($input);
        $distances  = [];

        foreach ($pool as $candidate) {
            $dist = levenshtein($inputLower, strtolower($candidate));
            // Only suggest if reasonably close (≤5 edits)
            if ($dist <= 5) {
                $distances[$candidate] = $dist;
            }
        }

        asort($distances);

        return array_slice(array_keys($distances), 0, 3);
    }
}
