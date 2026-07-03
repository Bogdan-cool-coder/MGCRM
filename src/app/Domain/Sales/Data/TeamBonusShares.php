<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * Result of MotivationCardService::teamBonusShares() — the 60/40 split for one
 * team member. Both parts are integer kopecks in the pool's currency.
 */
readonly class TeamBonusShares
{
    public function __construct(
        public int $part1Kopecks, // proportional (contribution-weighted)
        public int $part2Kopecks, // equal (pool / n_members)
    ) {}

    public function totalKopecks(): int
    {
        return $this->part1Kopecks + $this->part2Kopecks;
    }
}
