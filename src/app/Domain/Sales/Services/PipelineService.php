<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;

/**
 * PipelineService — read-only in S1.3 (pipelines/stages are seeded; the editor
 * — create/update/delete of pipelines and stages — lands in S1.5). All Pipeline
 * queries live here; the controller stays thin.
 */
class PipelineService
{
    /**
     * List pipelines (optionally filtered by kind) with ordered stages eager-loaded.
     *
     * @return Collection<int, Pipeline>
     */
    public function list(?string $kind = null): Collection
    {
        return Pipeline::query()
            ->with('stages')
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): Pipeline
    {
        return Pipeline::query()->with('stages')->findOrFail($id);
    }

    /**
     * Ordered stages for a pipeline.
     *
     * @return Collection<int, PipelineStage>
     */
    public function stagesFor(int $pipelineId): Collection
    {
        return PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The default sales pipeline (first active sales pipeline by sort order).
     */
    public function defaultSalesPipeline(): ?Pipeline
    {
        return Pipeline::query()
            ->where('kind', PipelineKind::Sales->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }
}
