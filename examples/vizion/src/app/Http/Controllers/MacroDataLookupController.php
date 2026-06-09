<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MacroData\EstateSells;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\DocumentObjectDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MacroData lookup endpoints consumed by the Documents section (M2).
 *
 * All routes are mounted under /api/macrodata/* and protected by the same
 * middleware stack as the rest of the API (auth:sanctum, locale, active.company,
 * company.access). The active company is resolved from request attributes
 * (set by the active.company middleware) — never from a query param.
 *
 * Endpoints:
 *   GET /api/macrodata/estate-sells/search?q=&limit=   — async-select suggestions
 *   GET /api/macrodata/estate-sells/{id}               — full object field map
 *   GET /api/macrodata/schema?model=EstateDeals        — column listing + types
 *
 * All three are read-only; no mutation of MacroData ever happens here.
 */
class MacroDataLookupController extends Controller
{
    /**
     * Whitelist of MacroData model short names accessible via the schema endpoint.
     *
     * Only models listed here can be introspected. This prevents arbitrary class
     * resolution from user-supplied input. Add a model to this list only when its
     * schema needs to be available to the document field picker UI.
     */
    protected const SCHEMA_MODEL_WHITELIST = [
        'EstateSells',
        'EstateDeals',
        'EstateHouses',
        'GeoCityComplex',
        'EstateRestoration',
        'Finances',
        'EstateDealsStatuses',
        'EstateStatuses',
        'Contacts',
        'Projects',
        'EstateBuys',
        'EstatePromos',
        'Users',
        'CompanyDepartments',
    ];

    public function __construct(
        protected ConnectionService $connectionService,
        protected DocumentObjectDataService $resolver,
    ) {}

    // -------------------------------------------------------------------------
    // estate-sells/search
    // -------------------------------------------------------------------------

    /**
     * Async-select search for estate objects.
     *
     * Returns an array of {value, label} items suitable for a select/combobox.
     * The label gives a human-readable description: flat number, complex name,
     * area — so the operator can quickly identify the right object.
     *
     * Query params:
     *   q      — search term matched against geo_flatnum (LIKE %q%)
     *   limit  — how many items to return (default 20, cap 50)
     *
     * @return JsonResponse  [{value: int, label: string}]
     */
    public function searchEstateSells(Request $request): JsonResponse
    {
        $company = $this->resolveActiveCompany($request);

        if ($company === null) {
            return response()->json([]);
        }

        try {
            $this->connectionService->connect($company);
        } catch (\Throwable) {
            return response()->json([]);
        }

        $q     = trim((string) $request->query('q', ''));
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $query = EstateSells::with('estateHouses.geoCityComplex');

        if ($q !== '') {
            $query->where('geo_flatnum', 'like', "%{$q}%");
        }

        $results = $query
            ->orderBy('geo_flatnum')
            ->limit($limit)
            ->get();

        $items = $results->map(fn (EstateSells $sell) => [
            'value' => $sell->estate_sell_id,
            'label' => $this->buildSellLabel($sell),
        ]);

        return response()->json($items);
    }

    // -------------------------------------------------------------------------
    // estate-sells/{id}
    // -------------------------------------------------------------------------

    /**
     * Detailed field map for a single estate object.
     *
     * Returns the same flat field => value map that DocumentObjectDataService
     * produces — so the frontend can preview placeholder values before generating
     * a document. Additionally wraps the data in a {data: {...}, label: string}
     * envelope so the caller gets a human-readable title alongside the fields.
     *
     * @param  int  $id  estate_sell_id
     */
    public function showEstateSell(Request $request, int $id): JsonResponse
    {
        $company = $this->resolveActiveCompany($request);

        if ($company === null) {
            return response()->json(['message' => __('macrodata.connection_unavailable')], 503);
        }

        $fields = $this->resolver->resolve($company, $id);

        if (empty($fields)) {
            return response()->json(['message' => __('macrodata.object_not_found')], 404);
        }

        // Build a readable label from already-resolved fields
        $label = $this->buildLabelFromFields($fields);

        return response()->json([
            'data'  => $fields,
            'label' => $label,
        ]);
    }

    // -------------------------------------------------------------------------
    // schema
    // -------------------------------------------------------------------------

    /**
     * Return column names and inferred types for a whitelisted MacroData model.
     *
     * Used by the document field-picker modal (M6) so operators can browse
     * available fields when building Word template placeholders.
     *
     * Query params:
     *   model — short class name, e.g. "EstateDeals" (must be in WHITELIST)
     *
     * Response:
     *   {model: string, table: string, fields: [{name: string, type: string}]}
     */
    public function schema(Request $request): JsonResponse
    {
        $modelShort = trim((string) $request->query('model', ''));

        if ($modelShort === '' || !in_array($modelShort, self::SCHEMA_MODEL_WHITELIST, true)) {
            return response()->json([
                'message'   => __('macrodata.schema_model_not_allowed'),
                'allowed'   => self::SCHEMA_MODEL_WHITELIST,
            ], 422);
        }

        $fullClass = "App\\Models\\MacroData\\{$modelShort}";

        if (!class_exists($fullClass)) {
            return response()->json(['message' => __('macrodata.schema_model_not_found')], 404);
        }

        $company = $this->resolveActiveCompany($request);

        if ($company === null) {
            return response()->json(['message' => __('macrodata.connection_unavailable')], 503);
        }

        try {
            $this->connectionService->connect($company);
        } catch (\Throwable) {
            return response()->json(['message' => __('macrodata.connection_unavailable')], 503);
        }

        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = new $fullClass;
        $table    = $instance->getTable();

        try {
            $columns = DB::connection('macrodata')
                ->getSchemaBuilder()
                ->getColumnListing($table);
        } catch (\Throwable) {
            return response()->json(['message' => __('macrodata.schema_unavailable')], 503);
        }

        // Use model casts as a hint for the field type displayed in the UI.
        // getCasts() is the public Eloquent API (merges the built-in timestamp casts
        // with the user-declared casts() method), safe to call on any Model instance.
        $casts = $instance->getCasts();

        $fields = array_map(fn (string $col) => [
            'name' => $col,
            'type' => $this->resolveFieldType($col, $casts),
        ], $columns);

        return response()->json([
            'model'  => $modelShort,
            'table'  => $table,
            'fields' => array_values($fields),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the active company from the request attributes.
     *
     * The active.company middleware sets `active_company_id` (int) on the request.
     * We use this — never `active_company` — because that attribute does not exist.
     * Falls back to the authenticated user's own company_id when the attribute is
     * absent (should not normally happen behind the middleware stack).
     */
    private function resolveActiveCompany(Request $request): ?Company
    {
        /** @var \App\Models\User|null $user */
        $user      = Auth::user();
        $companyId = (int) $request->attributes->get('active_company_id', $user?->company_id ?? 0);

        if ($companyId <= 0) {
            return null;
        }

        return Company::find($companyId);
    }

    /**
     * Build a human-readable label for an EstateSells instance.
     *
     * Format: "кв.45, ЖК Солнечный, 65.40 м²"
     * Falls back gracefully when relations are not loaded / null.
     */
    private function buildSellLabel(EstateSells $sell): string
    {
        $parts = [];

        $flatnum = $sell->geo_flatnum ?? '';
        if ($flatnum !== '') {
            $parts[] = "кв.{$flatnum}";
        }

        $complexName = $sell->estateHouses?->geoCityComplex?->geo_complex_name ?? '';
        if ($complexName !== '') {
            $parts[] = "ЖК {$complexName}";
        }

        $area = $sell->estate_area !== null ? (float) $sell->estate_area : null;
        if ($area !== null && $area > 0) {
            $parts[] = number_format($area, 2, '.', ' ') . ' м²';
        }

        return $parts !== [] ? implode(', ', $parts) : "Объект #{$sell->estate_sell_id}";
    }

    /**
     * Build a readable label from an already-resolved field map
     * (avoids a second DB hit in showEstateSell).
     *
     * Uses canonical group.field keys from DocumentObjectDataService v2.
     */
    private function buildLabelFromFields(array $fields): string
    {
        $parts = [];

        $flatnum = $fields['estate.number'] ?? '';
        if ($flatnum !== '') {
            $parts[] = "кв.{$flatnum}";
        }

        $complex = $fields['estate.complex_name'] ?? '';
        if ($complex !== '') {
            $parts[] = "ЖК {$complex}";
        }

        $area = $fields['estate.area'] ?? '';
        if ($area !== '' && $area !== '0') {
            $formatted = number_format((float) $area, 2, '.', ' ') . ' м²';
            $parts[] = $formatted;
        }

        // estate_sell_id is no longer a top-level key; use a safe fallback.
        $id = $fields['estate.number'] ?? '?';

        return $parts !== []
            ? implode(', ', $parts)
            : "Объект #{$id}";
    }

    /**
     * Resolve a display type string from the model's casts map.
     *
     * Covers the most common cases needed by the field-picker UI:
     *   decimal:* → 'decimal'
     *   int/integer → 'integer'
     *   date/datetime/timestamp → 'date'
     *   bool/boolean → 'boolean'
     *   anything else or absent → 'string'
     */
    private function resolveFieldType(string $column, array $casts): string
    {
        $cast = $casts[$column] ?? null;

        if ($cast === null) {
            return 'string';
        }

        $castLower = strtolower((string) $cast);

        if (str_starts_with($castLower, 'decimal')) {
            return 'decimal';
        }

        return match ($castLower) {
            'int', 'integer'                   => 'integer',
            'date', 'datetime', 'timestamp'    => 'date',
            'bool', 'boolean'                  => 'boolean',
            'float', 'double', 'real'          => 'decimal',
            default                            => 'string',
        };
    }
}
