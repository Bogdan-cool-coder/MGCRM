<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Services\TemplateVariableService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\StoreTemplateVariableRequest;
use App\Http\Requests\Contracts\UpdateTemplateVariableRequest;
use App\Http\Resources\Contracts\TemplateVariableResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateVariableController extends Controller
{
    public function __construct(
        private readonly TemplateVariableService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TemplateVariable::class);

        // If product_code and country_code provided → wildcard context filter.
        $productCode = $request->query('product_code');
        $countryCode = $request->query('country_code');

        if ($productCode !== null && $countryCode !== null) {
            $variables = $this->service->forContext($productCode, $countryCode);
        } else {
            // FE sends is_active (bool), active_only (legacy), or neither.
            $isActiveParam = $request->query('is_active');
            if ($isActiveParam !== null) {
                // FE bool filter: true = only active, false = all (admin manages inactive)
                $activeOnly = filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN);
            } else {
                $activeOnly = (bool) $request->query('active_only', true);
            }
            $group   = $request->query('group');
            $varType = $request->query('var_type');
            $search  = $request->query('search');
            $variables = $this->service->list($activeOnly, $group, $varType, $search);
        }

        return TemplateVariableResource::collection($variables);
    }

    public function show(Request $request, TemplateVariable $templateVariable): JsonResource
    {
        $this->authorize('view', $templateVariable);

        return TemplateVariableResource::make($templateVariable);
    }

    public function store(StoreTemplateVariableRequest $request): JsonResponse
    {
        $variable = $this->service->create($request->validated());

        return TemplateVariableResource::make($variable)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateTemplateVariableRequest $request, TemplateVariable $templateVariable): JsonResource
    {
        $variable = $this->service->update($templateVariable, $request->validated());

        return TemplateVariableResource::make($variable);
    }

    public function destroy(Request $request, TemplateVariable $templateVariable): JsonResponse
    {
        $this->authorize('delete', $templateVariable);

        $this->service->delete($templateVariable);

        return response()->json(null, 204);
    }
}
