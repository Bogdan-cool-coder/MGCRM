<?php

declare(strict_types=1);

namespace App\Services\MacroData;

use App\Models\Company;

/**
 * Resolves {"$company_var": "<semantic_key>"} placeholders inside a report
 * config array by looking up the semantic key value from the company's
 * macrodata_mappings table (via Company::macrodataValue()).
 *
 * Placeholders that cannot be resolved (key absent from mappings) are
 * replaced with an empty array []. This is a controlled degradation:
 * an empty IN-list produces 0 matching rows rather than a PHP error,
 * so the report still renders (with empty data) and the frontend can
 * display a warning via meta.unresolved_vars.
 *
 * Marker format (strict):
 *   {"$company_var": "<semantic_key>"}
 *
 * A node is a marker only when it is an associative array with exactly ONE
 * key — '$company_var' — whose value is a non-empty string. Any array that
 * has additional keys is treated as a regular config node and recursed into.
 *
 * Usage in ReportDataService:
 *   $unresolvedVars = [];
 *   $config = $this->configResolver->resolve($config, $company, $unresolvedVars);
 *   // $unresolvedVars contains semantic_keys that had no mapping
 */
class ConfigResolver
{
    /**
     * Resolve all company_var placeholders in the given config array.
     *
     * @param  array        $config         Raw report config (decoded from JSON).
     * @param  Company      $company        The company whose macrodataValue() is called.
     * @param  array|null   $unresolvedVars By-ref accumulator; resolved vars are NOT added.
     *                                      Unresolvable keys are appended (unique).
     * @return array                        Config with placeholders substituted.
     */
    public function resolve(array $config, Company $company, ?array &$unresolvedVars = []): array
    {
        return $this->walkNode($config, $company, $unresolvedVars);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Recursively walk a config node.
     *
     * @param  mixed        $node
     * @param  Company      $company
     * @param  array|null   $unresolvedVars
     * @return mixed
     */
    protected function walkNode(mixed $node, Company $company, ?array &$unresolvedVars): mixed
    {
        // Scalar (string, int, float, bool, null) — return as-is.
        if (!is_array($node)) {
            return $node;
        }

        // Detect pure marker: associative array with exactly one key '$company_var'.
        if ($this->isMarker($node)) {
            return $this->resolveMarker($node['$company_var'], $company, $unresolvedVars);
        }

        // Sequential (list) array: markers that resolve to arrays are spread inline
        // so that [marker_a, marker_b] → [1,2,3] instead of [[1,2],[3]].
        if (array_is_list($node)) {
            $result = [];
            foreach ($node as $item) {
                if (is_array($item) && $this->isMarker($item)) {
                    $resolved = $this->resolveMarker($item['$company_var'], $company, $unresolvedVars);
                    if (is_array($resolved)) {
                        // Spread: inline every element of the resolved array.
                        foreach ($resolved as $element) {
                            $result[] = $element;
                        }
                    } else {
                        // Scalar or null result: simple push (null passes through as-is).
                        $result[] = $resolved;
                    }
                } else {
                    $result[] = $this->walkNode($item, $company, $unresolvedVars);
                }
            }
            return $result;
        }

        // Associative array — recurse into each value, preserve keys.
        $result = [];
        foreach ($node as $key => $value) {
            $result[$key] = $this->walkNode($value, $company, $unresolvedVars);
        }

        return $result;
    }

    /**
     * Check whether a node is a company_var placeholder marker.
     *
     * Strict definition: array with exactly one key equal to '$company_var'
     * whose value is a non-empty string.
     */
    protected function isMarker(array $node): bool
    {
        if (count($node) !== 1) {
            return false;
        }

        if (!array_key_exists('$company_var', $node)) {
            return false;
        }

        $key = $node['$company_var'];

        return is_string($key) && $key !== '';
    }

    /**
     * Resolve a single semantic key to its company-specific value.
     *
     * Returns the mapped value (typically array<int>) on success.
     * Returns [] and appends to $unresolvedVars on failure.
     *
     * @param  string     $semanticKey
     * @param  Company    $company
     * @param  array|null $unresolvedVars
     * @return mixed
     */
    protected function resolveMarker(string $semanticKey, Company $company, ?array &$unresolvedVars): mixed
    {
        // Company::macrodataValue() is provided by backend-specialist via the
        // CompanyMacrodataMapping relation. It returns the stored value (mixed)
        // or null if the key is absent.
        $value = $company->macrodataValue($semanticKey);

        if ($value === null) {
            if ($unresolvedVars !== null && !in_array($semanticKey, $unresolvedVars, true)) {
                $unresolvedVars[] = $semanticKey;
            }

            // Controlled degradation: empty array causes IN () → 0 rows, not an error.
            return [];
        }

        return $value;
    }
}
