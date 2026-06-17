<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Services\CustomFieldService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreCustomFieldDefRequest;
use App\Http\Requests\Crm\UpdateCustomFieldDefRequest;
use App\Http\Resources\Crm\CustomFieldDefResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * Manages CustomFieldDef admin CRUD.
 * Routes: /crm/custom-fields
 */
class CustomFieldDefController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $service,
    ) {}

    /**
     * GET /crm/custom-fields?scope=company|contact
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'scope' => ['nullable', 'string', Rule::enum(CustomFieldScope::class)],
        ]);

        $scope = $request->query('scope')
            ? CustomFieldScope::from($request->query('scope'))
            : null;

        $defs = $scope
            ? $this->service->defsForScope($scope)
            : CustomFieldDef::orderBy('entity_scope')->orderBy('sort_order')->get();

        return CustomFieldDefResource::collection($defs);
    }

    public function store(StoreCustomFieldDefRequest $request): JsonResource
    {
        $this->authorize('admin-write');

        $def = $this->service->createDef($request->validated());

        return CustomFieldDefResource::make($def);
    }

    public function show(Request $request, CustomFieldDef $customFieldDef): JsonResource
    {
        return CustomFieldDefResource::make($customFieldDef);
    }

    public function update(UpdateCustomFieldDefRequest $request, CustomFieldDef $customFieldDef): JsonResource
    {
        $this->authorize('admin-write');

        $def = $this->service->updateDef($customFieldDef, $request->validated());

        return CustomFieldDefResource::make($def);
    }

    public function destroy(Request $request, CustomFieldDef $customFieldDef): JsonResponse
    {
        $this->authorize('admin-write');

        $this->service->deleteDef($customFieldDef);

        return response()->json(['message' => 'Custom field definition deleted.']);
    }
}
