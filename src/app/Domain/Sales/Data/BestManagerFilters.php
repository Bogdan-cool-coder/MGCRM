<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * Immutable filter set for R3 «Лучший менеджер» (contract §6.6).
 * A year-scoped leaderboard (no month drill), optional pipeline filter and
 * a standard/absolute ranking mode toggle (SpaceCRM «Стандартный/Абсолютный»).
 */
readonly class BestManagerFilters
{
    public function __construct(
        public int $year,
        public string $mode = 'standard',
        public ?int $pipelineId = null,
    ) {}

    public static function forYear(int $year, string $mode = 'standard', ?int $pipelineId = null): self
    {
        return new self(year: $year, mode: $mode, pipelineId: $pipelineId);
    }
}
