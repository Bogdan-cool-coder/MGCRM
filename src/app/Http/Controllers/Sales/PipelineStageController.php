<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\PipelineService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\PipelineStageResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin PipelineStage controller — index only in S1.3.
 * store/update/delete (the funnel editor) land in S1.5.
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
}
