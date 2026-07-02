<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * LostReasonService — registry CRUD for deal-loss reasons.
 */
class LostReasonService
{
    /**
     * @return Collection<int, LostReason>
     */
    public function list(bool $activeOnly = false): Collection
    {
        return LostReason::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LostReason
    {
        return LostReason::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LostReason $lostReason, array $data): LostReason
    {
        $lostReason->update($data);
        $lostReason->refresh();

        return $lostReason;
    }

    /**
     * Delete a lost reason. Refused (409) if it is referenced by any deal.
     */
    public function delete(LostReason $lostReason): void
    {
        if (Deal::query()->where('lost_reason_id', $lostReason->id)->exists()) {
            throw ValidationException::withMessages([
                'lost_reason' => 'Cannot delete a lost reason that is used by deals.',
            ])->status(409);
        }

        $lostReason->delete();
    }

    /**
     * Purge ALL lost reasons — the Sales-owned slice of the `directories` reset
     * category (docs/contracts/system-reset-api-contract.md §1 row 9, §7). Called
     * ONLY by the cross-domain `App\Support\System\SystemResetService` orchestrator,
     * never from a controller.
     *
     * Unlike delete(), this ignores the deal-reference guard: the reset always
     * co-selects `deals` before `directories` (contract §3 prerequisite matrix), so
     * no deal references a lost_reason by the time this runs; `deals.lost_reason_id`
     * is `nullOnDelete()` regardless, so there is no FK RESTRICT hazard.
     *
     * No own transaction — the orchestrator wraps the whole category in one
     * DB::transaction() (contract §5).
     *
     * @return array<string, int> counts keyed by table name
     */
    public function purgeAll(): array
    {
        $count = LostReason::query()->count();
        LostReason::query()->delete();

        return ['lost_reasons' => $count];
    }
}
