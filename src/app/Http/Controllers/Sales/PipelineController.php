<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\PipelineService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\PipelineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin Pipeline controller (read-only in S1.3). Pipeline/stage CRUD lands in S1.5.
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
}
