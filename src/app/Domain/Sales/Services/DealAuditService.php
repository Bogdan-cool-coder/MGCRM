<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * DealAuditService — records and paginates the append-only deal field log.
 *
 * record() takes a per-field diff ([field => ['old' => .., 'new' => ..]]) and
 * writes one deal_audits row per changed field. The "extra_fields" key is a
 * special case: its old/new are jsonb maps, expanded PER KEY into separate rows
 * (field = "extra_fields.{code}") so the timeline shows granular custom-field
 * changes (decision 2026-06-15). Rows are inserted in a single batch.
 */
class DealAuditService
{
    /**
     * @param  array<string, array{old?: mixed, new?: mixed}>  $changes
     */
    public function record(Deal $deal, ?User $actor, array $changes): void
    {
        $now = now();
        $userId = $actor?->id;
        $rows = [];

        foreach ($changes as $field => $diff) {
            $old = $diff['old'] ?? null;
            $new = $diff['new'] ?? null;

            if ($field === 'extra_fields') {
                foreach ($this->expandExtraFields($old, $new) as $key => $keyDiff) {
                    $rows[] = [
                        'deal_id' => $deal->id,
                        'user_id' => $userId,
                        'field' => "extra_fields.{$key}",
                        'old_value' => $this->encode($keyDiff['old']),
                        'new_value' => $this->encode($keyDiff['new']),
                        'created_at' => $now,
                    ];
                }

                continue;
            }

            $rows[] = [
                'deal_id' => $deal->id,
                'user_id' => $userId,
                'field' => $field,
                'old_value' => $this->encode($old),
                'new_value' => $this->encode($new),
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DealAudit::query()->insert($rows);
    }

    public function forDeal(Deal $deal, int $perPage = 50): LengthAwarePaginator
    {
        return DealAudit::query()
            ->where('deal_id', $deal->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Expand the extra_fields old/new maps into a per-key diff, keeping only
     * keys whose value actually changed.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function expandExtraFields(mixed $old, mixed $new): array
    {
        $oldMap = is_array($old) ? $old : [];
        $newMap = is_array($new) ? $new : [];

        $keys = array_keys($oldMap + $newMap);
        $result = [];

        foreach ($keys as $key) {
            $oldValue = $oldMap[$key] ?? null;
            $newValue = $newMap[$key] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $result[$key] = ['old' => $oldValue, 'new' => $newValue];
        }

        return $result;
    }

    /**
     * Encode a value for the text column: scalars as-is (string), arrays/objects
     * as JSON, null preserved as null.
     */
    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
