<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMacrodataMapping;
use App\Services\MacroData\CompanySchemaProbeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin endpoints for managing per-company MacroData ID mappings.
 *
 * Each row resolves a stable `semantic_key` (e.g. `finance_type_sale_ids`) to
 * the client's actual MacroData internal IDs. Report configs reference these
 * via `{"$company_var": "<semantic_key>"}` placeholders which the consumer
 * (macrodata-engineer's ConfigResolver) expands at query time.
 *
 * ACL:
 *  - superadmin: any company
 *  - admin:      only their own company (via canAccessCompany())
 *  - analyst/viewer: forbidden
 *
 * `CheckCompanyAccess` middleware only intercepts when `company_id` is in the
 * route or input — our routes use route-model binding (`{company}`), so this
 * controller handles the access check inline (same pattern as
 * CompanyController::update).
 */
class CompanyMacrodataMappingController extends Controller
{
    /**
     * GET /api/companies/{company}/macrodata-mappings
     *
     * Returns every mapping for the given company, ordered alphabetically by
     * semantic_key for stable UI rendering.
     */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->assertWriteAccess($request, $company);

        $mappings = $company->macrodataMappings()
            ->orderBy('semantic_key')
            ->get()
            ->map(fn (CompanyMacrodataMapping $m) => $this->serialize($m));

        return response()->json(['data' => $mappings->all()]);
    }

    /**
     * PUT /api/companies/{company}/macrodata-mappings
     *
     * Bulk upsert. Each item in the `mappings` array is matched against the
     * unique (company_id, semantic_key) index — existing rows are updated,
     * new rows are created. Items that aren't included in the payload remain
     * untouched (partial updates by design — frontend's "save row" UX
     * shouldn't accidentally wipe sibling rows).
     *
     * All writes happen in one transaction.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        $this->assertWriteAccess($request, $company);

        $validated = $request->validate([
            'mappings'              => ['required', 'array'],
            // ^[a-z][a-z0-9_]*$ — snake_case starting with a letter. Cap at
            // 100 to match the column length. Reject uppercase/spaces/dashes
            // so that ConfigResolver can rely on a normalised key format
            // (placeholders in report configs use the same shape).
            'mappings.*.semantic_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            // `present` (not `required`) — explicit null is a valid jsonb
            // payload meaning "clear the value". `array` would reject scalars,
            // but the spec explicitly allows int/string values for future
            // semantic_keys. So no type rule here beyond presence.
            'mappings.*.value'        => ['present'],
            'mappings.*.notes'        => ['nullable', 'string'],
            // `auto_probed_at` is normally stamped by CompanySchemaProbeService,
            // but the UI "Применить" flow on the probe dialog persists the
            // accepted suggestions through this same endpoint and tags them
            // with `new Date().toISOString()` so the row is visibly marked as
            // probe-sourced. Accept null (explicit clear) or an ISO-8601
            // datetime string; absence of the key means "leave existing value
            // alone" (handled below in the upsert payload assembly).
            'mappings.*.auto_probed_at' => ['nullable', 'date'],
        ]);

        // Pull the raw input back from the request — Laravel's validator drops
        // keys that weren't present in the payload at all, but for partial
        // upsert we need to distinguish "key absent" (preserve existing) from
        // "key present and null" (clear). `$request->input('mappings')` keeps
        // both cases distinguishable via `array_key_exists`.
        $rawMappings = $request->input('mappings', []);

        DB::transaction(function () use ($company, $validated, $rawMappings) {
            foreach ($validated['mappings'] as $idx => $row) {
                $updateData = [
                    'value' => $row['value'],
                    'notes' => $row['notes'] ?? null,
                ];

                // Partial update: only touch auto_probed_at when the caller
                // explicitly sent the key. Manual edits from the inline form
                // omit it and must not wipe the probe timestamp.
                $rawRow = $rawMappings[$idx] ?? [];
                if (is_array($rawRow) && array_key_exists('auto_probed_at', $rawRow)) {
                    // The validator already accepted null or a parseable date
                    // string; the model's datetime cast handles the rest.
                    $updateData['auto_probed_at'] = $row['auto_probed_at'] ?? null;
                }

                $company->macrodataMappings()->updateOrCreate(
                    ['semantic_key' => $row['semantic_key']],
                    $updateData
                );
            }
        });

        // Reload the cache snapshot for the response — the trailing GET-shape
        // payload lets the frontend update its store without a second round
        // trip.
        return $this->index($request, $company->fresh());
    }

    /**
     * DELETE /api/companies/{company}/macrodata-mappings/{semantic_key}
     *
     * Hard-delete a single mapping. 204 on success, 404 when the key was
     * absent (idempotency would silently mask client bugs, so we report).
     */
    public function destroy(Request $request, Company $company, string $semanticKey): JsonResponse
    {
        $this->assertWriteAccess($request, $company);

        // Re-validate the URL segment with the same regex as the bulk write
        // path — a stray uppercase letter or pipe character shouldn't even
        // hit the DB.
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $semanticKey) || strlen($semanticKey) > 100) {
            return response()->json(['message' => 'Invalid semantic_key shape.'], 422);
        }

        $deleted = $company->macrodataMappings()
            ->where('semantic_key', $semanticKey)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Mapping not found.'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * POST /api/companies/{company}/macrodata-mappings/probe
     *
     * Walks MacroData's lookup tables (e.g. `finances_types`), matches RU/EN
     * name fragments against known semantic_keys (see `config/macrodata_probe.php`)
     * and returns a *proposal* — the user confirms in the UI and then POSTs the
     * accepted subset via PUT.
     *
     * Contract (kept in sync with CompanySchemaProbeService::probe):
     *
     *  {
     *    "data": {
     *      "probed_at": "2026-05-21T...",
     *      "mappings": [
     *        {
     *          "semantic_key": "finance_type_sale_ids",
     *          "value": [3786],
     *          "matched_by": "RU: '%Поступления от продажи%'",
     *          "candidates": [
     *            {"id": 3786, "name": "Поступления от продажи недвижимости"}
     *          ]
     *        }
     *      ],
     *      "unresolved": ["finance_type_some_other_ids"]
     *    }
     *  }
     *
     * Probe is strictly read-only — the response is a suggestion, nothing is
     * persisted. The user confirms via PUT /macrodata-mappings.
     *
     * Errors:
     *  - 503 when the MacroData replica can't be reached or the probe blows up
     *    mid-flight (missing creds, network, PDO errors). We do NOT surface raw
     *    exception messages to the client — they may leak DSN / host details.
     */
    public function probe(Request $request, Company $company, CompanySchemaProbeService $probeService): JsonResponse
    {
        $this->assertWriteAccess($request, $company);

        try {
            $result = $probeService->probe($company);
        } catch (Throwable $e) {
            Log::error('CompanySchemaProbeService failed', [
                'company_id' => $company->id,
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'macrodata_unavailable',
                'message' => __('companies.macrodata_probe_failed'),
            ], 503);
        }

        // Carbon -> ISO-8601 explicitly. Laravel's JSON serialiser would format
        // it too, but the default format differs by Carbon version — pin it.
        return response()->json([
            'data' => [
                'probed_at'  => $result['probed_at']->toIso8601String(),
                'mappings'   => $result['mappings'],
                'unresolved' => $result['unresolved'],
            ],
        ]);
    }

    /**
     * Inline ACL: superadmin → any company; admin → only own (canAccessCompany);
     * analyst/viewer → forbidden. Mirrors CompanyController::update.
     */
    private function assertWriteAccess(Request $request, Company $company): void
    {
        $user = $request->user();

        $allowed = $user->role === 'superadmin'
            || ($user->role === 'admin' && $user->canAccessCompany($company->id));

        if (!$allowed) {
            abort(response()->json(['message' => __('auth.forbidden')], 403));
        }
    }

    /**
     * Single serialisation point so index() and update() never drift.
     */
    private function serialize(CompanyMacrodataMapping $m): array
    {
        return [
            'id'             => $m->id,
            'semantic_key'   => $m->semantic_key,
            'value'          => $m->value,
            'notes'          => $m->notes,
            'auto_probed_at' => $m->auto_probed_at?->toIso8601String(),
            'updated_at'     => $m->updated_at?->toIso8601String(),
        ];
    }
}
