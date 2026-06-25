<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam\Admin;

use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Services\VisibilityConfigService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\UpdateVisibilityConfigRequest;
use Illuminate\Http\JsonResponse;

/**
 * Settings → Access Control → Visibility. Reads/edits the role × visibility-scope
 * matrix that drives VisibilityResolver at runtime. Same admin/director gate
 * (`admin-write`); changes are audited (entity_logs) and bust the resolver cache
 * in the service.
 */
class VisibilityConfigController extends Controller
{
    public function index(VisibilityConfigService $service): JsonResponse
    {
        $this->authorize('admin-write');

        return response()->json(['data' => $this->present($service->map())]);
    }

    public function update(
        UpdateVisibilityConfigRequest $request,
        VisibilityConfigService $service,
    ): JsonResponse {
        $this->authorize('admin-write');

        /** @var array<string, string> $config */
        $config = $request->validated('config');
        $map = $service->update($config, $request->user());

        return response()->json(['data' => $this->present($map)]);
    }

    /**
     * Render a role => VisibilityScope map as a flat { role: scope-value } object.
     *
     * @param  array<string, VisibilityScope>  $map
     * @return array<string, string>
     */
    private function present(array $map): array
    {
        return array_map(static fn ($scope): string => $scope->value, $map);
    }
}
