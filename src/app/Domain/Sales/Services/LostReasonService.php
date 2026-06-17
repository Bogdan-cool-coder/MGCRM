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
}
