<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Services\CustomFieldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\IndexCustomFieldDefsRequest;
use App\Http\Requests\Crm\ReorderCustomFieldDefsRequest;
use App\Http\Requests\Crm\SchemaCustomFieldDefsRequest;
use App\Http\Requests\Crm\StoreCustomFieldDefRequest;
use App\Http\Requests\Crm\UpdateCustomFieldDefRequest;
use App\Http\Resources\Crm\CustomFieldDefResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Manages CustomFieldDef admin CRUD.
 * Routes: /crm/custom-fields
 *
 * Route ordering is critical: schema and reorder MUST be declared BEFORE apiResource
 * in api.php to prevent the static segment from matching as the {customFieldDef} parameter.
 */
class CustomFieldDefController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $service,
    ) {}

    /**
     * GET /crm/custom-fields/schema?entity_scope=contact|company|deal|contract
     *
     * Returns active-only field definitions grouped by `group` and sorted by `sort_order`.
     * Designed for CustomFieldRenderer.vue — provides everything needed to render
     * a form for any entity scope without the front having to group manually.
     *
     * MUST be declared BEFORE apiResource (route order matters).
     */
    public function schema(SchemaCustomFieldDefsRequest $request): JsonResponse
    {
        $scope = CustomFieldScope::from($request->query('entity_scope'));
        $defs = $this->service->defsForScope($scope); // active-only, for form render

        // Group by `group` field, sorted by sort_order within each group
        $grouped = $defs->groupBy('group')->map(static function ($fields, string $group): array {
            return [
                'group' => $group,
                'fields' => CustomFieldDefResource::collection($fields)->resolve(),
            ];
        })->values();

        return response()->json(['data' => $grouped]);
    }

    /**
     * PATCH /crm/custom-fields/reorder?entity_scope=<scope>
     *
     * Bulk sort_order update for one entity_scope. Accepts {items: [{id, sort_order}]}.
     * All ids must belong to the given scope — cross-scope ids return 422.
     *
     * MUST be declared BEFORE apiResource (route order matters).
     */
    public function reorder(ReorderCustomFieldDefsRequest $request): JsonResponse
    {
        $this->authorize('admin-write');

        $scope = CustomFieldScope::from($request->query('entity_scope'));
        $this->service->reorder($scope, $request->validated('items'));

        return response()->json(['message' => 'Custom field order updated.']);
    }

    /**
     * GET /crm/custom-fields?scope=company|contact|deal|contract&include_inactive=1
     *
     * Admin list — includes inactive defs by default (G5b).
     * Pass `?include_inactive=0` to limit to active-only.
     *
     * `defsForScope()` (active-only) is intentionally NOT used here; it is reserved
     * for form-render (schema endpoint) and writeFields validation.
     */
    public function index(IndexCustomFieldDefsRequest $request): AnonymousResourceCollection
    {
        $scope = $request->query('scope')
            ? CustomFieldScope::from($request->query('scope'))
            : null;

        // Default: include inactive (admin list shows all); pass 0 to narrow to active.
        $includeInactive = filter_var(
            $request->query('include_inactive', '1'),
            FILTER_VALIDATE_BOOLEAN
        );

        $defs = $this->service->listDefs($scope, (bool) $includeInactive);

        return CustomFieldDefResource::collection($defs);
    }

    public function store(StoreCustomFieldDefRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('admin-write');

        $def = $this->service->createDef($request->validated());

        return CustomFieldDefResource::make($def)
            ->response()
            ->setStatusCode(201); // G9: 201 Created
    }

    public function show(\App\Domain\Crm\Models\CustomFieldDef $customFieldDef): JsonResource
    {
        return CustomFieldDefResource::make($customFieldDef);
    }

    public function update(UpdateCustomFieldDefRequest $request, \App\Domain\Crm\Models\CustomFieldDef $customFieldDef): JsonResource
    {
        $this->authorize('admin-write');

        $def = $this->service->updateDef($customFieldDef, $request->validated());

        return CustomFieldDefResource::make($def);
    }

    public function destroy(\App\Domain\Crm\Models\CustomFieldDef $customFieldDef): \Illuminate\Http\Response
    {
        $this->authorize('admin-write');

        $this->service->deleteDef($customFieldDef);

        return response()->noContent(); // G9: 204 No Content
    }
}
