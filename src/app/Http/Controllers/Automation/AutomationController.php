<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\Exceptions\DryRunTargetRequiredException;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationQueryService;
use App\Domain\Automation\Services\AutomationService;
use App\Domain\Automation\Services\AutomationTestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\IndexAutomationRequest;
use App\Http\Requests\Automation\StoreAutomationRequest;
use App\Http\Requests\Automation\TestAutomationRequest;
use App\Http\Requests\Automation\UpdateAutomationRequest;
use App\Http\Resources\Automation\AutomationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * AutomationController (M7 P4) — thin CRUD + dry-run for the automation builder.
 *
 * Pass-through: authorize → (service) → resource. Filtering, persistence and
 * simulation all live in the domain services (ARCHITECTURE §1). The whole
 * resource is admin/director-gated via PipelineAutomationPolicy.
 */
class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationService $service,
        private readonly AutomationQueryService $queries,
        private readonly AutomationTestService $tester,
    ) {}

    public function index(IndexAutomationRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PipelineAutomation::class);

        return AutomationResource::collection(
            $this->queries->list($request->filters()),
        );
    }

    public function store(StoreAutomationRequest $request): JsonResource
    {
        $this->authorize('create', PipelineAutomation::class);

        $automation = $this->service->create($request->payload(), $request->user()->id);

        return AutomationResource::make($automation->load(['pipeline:id,name', 'stage:id,name']));
    }

    public function show(PipelineAutomation $automation): JsonResource
    {
        $this->authorize('view', $automation);

        return AutomationResource::make(
            $automation->load(['pipeline:id,name', 'stage:id,name'])->loadCount('runs'),
        );
    }

    public function update(UpdateAutomationRequest $request, PipelineAutomation $automation): JsonResource
    {
        $this->authorize('update', $automation);

        $updated = $this->service->update($automation, $request->payload());

        return AutomationResource::make($updated->load(['pipeline:id,name', 'stage:id,name']));
    }

    public function destroy(PipelineAutomation $automation): Response
    {
        $this->authorize('delete', $automation);

        $this->service->delete($automation);

        return response()->noContent();
    }

    /**
     * Dry-run: preview which deals match and what the action WOULD do, with no
     * side-effect and no AutomationRun written. Inline triggers require a pinned
     * target — a missing one is a 422 telling the admin to pick a deal.
     */
    public function test(TestAutomationRequest $request, PipelineAutomation $automation): JsonResponse
    {
        $this->authorize('test', $automation);

        try {
            $result = $this->tester->dryRun($automation, $request->targetId(), $request->limit());
        } catch (DryRunTargetRequiredException $e) {
            return response()->json([
                'message' => 'Pick a specific deal to preview this trigger.',
                'errors' => ['target_id' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $result->toArray()]);
    }
}
