<?php

declare(strict_types=1);

namespace App\Services\MacroData;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Probes a company's MacroData replica to discover per-company IDs
 * for semantic concepts defined in config/macrodata_probe.php.
 *
 * Probe is read-only and returns a proposal — nothing is persisted.
 * The caller (controller / command) is responsible for writing results
 * to company_macrodata_mappings via PUT /api/companies/{id}/macrodata-mappings.
 *
 * Exceptions from ConnectionService are propagated unchanged so the
 * controller can return an appropriate error response (e.g. 503).
 */
class CompanySchemaProbeService
{
    public function __construct(
        protected readonly ConnectionService $connectionService,
    ) {}

    /**
     * Run a schema probe for the given company.
     *
     * @param  Company $company  Must have macrodata_* credentials configured.
     * @return array{
     *   probed_at: Carbon,
     *   mappings: list<array{
     *     semantic_key: string,
     *     value: list<int>,
     *     matched_by: string,
     *     candidates: list<array{id: int, name: string}>,
     *   }>,
     *   unresolved: list<string>,
     * }
     *
     * @throws \RuntimeException  If ConnectionService cannot connect.
     */
    public function probe(Company $company): array
    {
        // May throw — caller handles.
        $this->connectionService->connect($company);

        $semanticKeys = config('macrodata_probe.semantic_keys', []);

        $mappings   = [];
        $unresolved = [];

        foreach ($semanticKeys as $semanticKey => $definition) {
            $result = $this->probeKey($semanticKey, $definition);

            $mappings[] = $result;

            if (empty($result['value'])) {
                $unresolved[] = $semanticKey;
            }
        }

        return [
            'probed_at' => Carbon::now(),
            'mappings'  => $mappings,
            'unresolved' => $unresolved,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Probe one semantic key against MacroData.
     *
     * @param  string $semanticKey  e.g. 'finance_type_sale_ids'
     * @param  array  $definition   Entry from config('macrodata_probe.semantic_keys')
     * @return array{
     *   semantic_key: string,
     *   value: list<int>,
     *   matched_by: string,
     *   candidates: list<array{id: int, name: string}>,
     * }
     */
    protected function probeKey(string $semanticKey, array $definition): array
    {
        $table      = $definition['table'];
        $valueField = $definition['value_field'];
        $matchField = $definition['match_field'];
        $patterns   = $definition['patterns'] ?? [];

        // Fetch ALL rows from the lookup table — these tables are small (5-50 rows).
        // We fold them in PHP to avoid N+1 queries and to apply case-insensitive matching
        // across locales without relying on MySQL collation settings of the remote replica.
        $rows = DB::connection('macrodata')
            ->table($table)
            ->select([$valueField, $matchField])
            ->get();

        // Flatten patterns to a list of ['locale' => ..., 'pattern' => ...] entries
        // so we can report which locale+pattern triggered a match.
        $flatPatterns = [];
        foreach ($patterns as $locale => $localePatterns) {
            foreach ($localePatterns as $pattern) {
                $flatPatterns[] = [
                    'locale'  => strtoupper($locale),
                    'pattern' => $pattern,
                ];
            }
        }

        $valueIds       = [];
        $candidates     = [];
        $matchedByParts = [];

        foreach ($rows as $row) {
            $rowArray  = (array) $row;
            $id        = (int) $rowArray[$valueField];
            $name      = (string) ($rowArray[$matchField] ?? '');
            $nameLower = mb_strtolower($name);

            $matchedPatterns = [];
            foreach ($flatPatterns as $entry) {
                if ($this->likeMatch($nameLower, $entry['pattern'])) {
                    $matchedPatterns[] = "{$entry['locale']}: {$entry['pattern']}";
                }
            }

            if (!empty($matchedPatterns)) {
                $valueIds[]   = $id;
                $candidates[] = ['id' => $id, 'name' => $name];

                foreach ($matchedPatterns as $mp) {
                    if (!in_array($mp, $matchedByParts, true)) {
                        $matchedByParts[] = $mp;
                    }
                }
            }
        }

        // Deduplicate IDs (a row could conceivably match multiple patterns).
        $valueIds = array_values(array_unique($valueIds));

        return [
            'semantic_key' => $semanticKey,
            'value'        => $valueIds,
            'matched_by'   => implode(' / ', $matchedByParts),
            'candidates'   => $candidates,
        ];
    }

    /**
     * Evaluate a SQL-LIKE pattern (using '%' wildcard) against a pre-lowercased
     * subject string. The pattern is also lowercased here so matching is always
     * case-insensitive regardless of the source locale.
     *
     * Handles only the '%' wildcard (multi-character); '_' is treated literally
     * because none of our probe patterns use it.
     */
    protected function likeMatch(string $subject, string $pattern): bool
    {
        // Lowercase the pattern (patterns in config are already lowercase,
        // but be defensive).
        $patternLower = mb_strtolower($pattern);

        // Convert SQL LIKE pattern to a regex:
        // 1. Escape all regex meta-characters in the pattern (except %).
        // 2. Replace % with .* (greedy).
        $escaped = preg_quote($patternLower, '/');
        $regex   = '/^' . str_replace('%', '.*', $escaped) . '$/us';

        return (bool) preg_match($regex, $subject);
    }
}
