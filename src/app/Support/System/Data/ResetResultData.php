<?php

declare(strict_types=1);

namespace App\Support\System\Data;

/**
 * Immutable result of a selective system reset (contract §6.2 success shape).
 * Best-effort per-category: `deleted` holds the counts for categories that
 * committed, `failed` names the categories whose transaction rolled back (with the
 * error message), so the operator sees exactly how far the run got (§5). Surfaced
 * through SystemResetResource.
 */
final readonly class ResetResultData
{
    /**
     * @param  array<string, int>  $deleted  category key => rows deleted (committed only)
     * @param  list<array{category: string, error: string}>  $failed  categories whose tx rolled back
     */
    public function __construct(
        public array $deleted,
        public array $failed,
    ) {}

    public function totalDeleted(): int
    {
        return array_sum($this->deleted);
    }
}
