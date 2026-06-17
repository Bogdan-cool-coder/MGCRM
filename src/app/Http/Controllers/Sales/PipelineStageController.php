<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\PipelineService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReorderStagesRequest;
use App\Http\Requests\Sales\StoreStageRequest;
use App\Http\Requests\Sales\UpdateStageRequest;
use App\Http\Resources\Sales\PipelineStageResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin PipelineStage controller — the funnel editor (S1.5). A stage is part of a
 * pipeline, so every write is gated on the pipeline (admin/director) — there is
 * no separate StagePolicy. Stages not belonging to the route pipeline → 404.
 */
class PipelineStageController extends Controller
{
    public function __construct(
        private readonly PipelineService $service,
    ) {}

    public function index(Pipeline $pipeline): AnonymousResourceCollection
    {
        $this->authorize('view', $pipeline);

        return PipelineStageResource::collection($this->service->stagesFor($pipeline->id));
    }

    public function store(StoreStageRequest $request, Pipeline $pipeline): JsonResource
    {
        $this->authorize('update', $pipeline);

        $stage = $this->service->createStage($pipeline, $request->validated());

        return PipelineStageResource::make($stage);
    }

    public function update(UpdateStageRequest $request, Pipeline $pipeline, PipelineStage $stage): JsonResource
    {
        $this->authorize('update', $pipeline);
        $this->assertStageInPipeline($pipeline, $stage);

        $updated = $this->service->updateStage($stage, $request->validated());

        return PipelineStageResource::make($updated);
    }

    public function destroy(Pipeline $pipeline, PipelineStage $stage): Response
    {
        $this->authorize('update', $pipeline);
        $this->assertStageInPipeline($pipeline, $stage);

        $this->service->deleteStage($stage);

        return response()->noContent();
    }

    public function reorder(ReorderStagesRequest $request, Pipeline $pipeline): AnonymousResourceCollection
    {
        $this->authorize('update', $pipeline);

        $stages = $this->service->reorderStages($pipeline, $request->validated('stages'));

        return PipelineStageResource::collection($stages);
    }

    private function assertStageInPipeline(Pipeline $pipeline, PipelineStage $stage): void
    {
        abort_unless((int) $stage->pipeline_id === (int) $pipeline->id, 404);
    }
}
