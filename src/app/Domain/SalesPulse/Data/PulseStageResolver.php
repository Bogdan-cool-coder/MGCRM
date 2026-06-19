<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

use App\Domain\Sales\Models\PipelineStage;

/**
 * PulseStageResolver — a tiny stage_id → PipelineStage lookup the renderers and
 * report services share so they can resolve a deal's emoji / SLA / sort-key
 * without each one re-querying the DB.
 *
 * The map is built once by the caller (load the PipelineStage rows referenced by
 * a snapshot, key by id) and passed into the renderers. A missing/null stage_id
 * resolves to null, which StageMeta / StageClassificationService both treat as
 * the neutral default — so the renderers stay total and DB-free in tests (a test
 * builds the map by hand or from the seeded funnel).
 *
 * Pure value holder — no DB access of its own.
 */
final class PulseStageResolver
{
    /**
     * @param  array<int, PipelineStage>  $byId  stage_id => PipelineStage
     */
    public function __construct(
        private array $byId = [],
    ) {}

    /**
     * Build a resolver from a list of PipelineStage models.
     *
     * @param  iterable<PipelineStage>  $stages
     */
    public static function fromStages(iterable $stages): self
    {
        $map = [];
        foreach ($stages as $stage) {
            $map[(int) $stage->id] = $stage;
        }

        return new self($map);
    }

    public function resolve(?int $stageId): ?PipelineStage
    {
        if ($stageId === null) {
            return null;
        }

        return $this->byId[$stageId] ?? null;
    }

    /**
     * The stage name to print: prefer the live stage's name, else the snapshot's
     * cached deal_stage_name (spec §2 — name is recovered from tasks[].deal_stage_name).
     */
    public function name(?int $stageId, ?string $fallbackName): string
    {
        $stage = $this->resolve($stageId);

        return $stage?->name ?? ($fallbackName ?? '');
    }
}
