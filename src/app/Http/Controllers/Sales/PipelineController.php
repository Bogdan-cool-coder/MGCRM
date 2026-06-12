<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\PipelineService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePipelineRequest;
use App\Http\Requests\Sales\UpdatePipelineRequest;
use App\Http\Resources\Sales\PipelineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin Pipeline controller. Reads are open; CRUD is admin/director (policy).
 * The editor (pipeline + stage CRUD) lands in S1.5.
 */
class PipelineController extends Controller
{
    public function __construct(
        private readonly PipelineService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pipeline::class);

        $pipelines = $this->service->list($request->query('kind'));

        return PipelineResource::collection($pipelines);
    }

    public function show(Request $request, Pipeline $pipeline): JsonResource
    {
        $this->authorize('view', $pipeline);

        return PipelineResource::make($pipeline->load('stages'));
    }

    public function store(StorePipelineRequest $request): JsonResource
    {
        $this->authorize('create', Pipeline::class);

        $pipeline = $this->service->create($request->validated());

        return PipelineResource::make($pipeline);
    }

    public function update(UpdatePipelineRequest $request, Pipeline $pipeline): JsonResource
    {
        $this->authorize('update', $pipeline);

        $updated = $this->service->update($pipeline, $request->validated());

        return PipelineResource::make($updated);
    }

    public function destroy(Request $request, Pipeline $pipeline): Response
    {
        $this->authorize('delete', $pipeline);

        $this->service->delete($pipeline);

        return response()->noContent();
    }
}
