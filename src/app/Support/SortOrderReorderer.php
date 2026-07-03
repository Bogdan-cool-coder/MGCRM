<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared drag-and-drop reorder helper for flat `sort_order` directories.
 *
 * The single source of truth for the "reorder a flat directory" pattern used by
 * the admin reference catalogs (countries, tags, acquisition-channels,
 * disconnect-reasons, lost-reasons, ...). Rather than each directory service
 * re-implementing the same lock → validate-membership → dense-renumber loop, they
 * all funnel through here.
 *
 * Semantics (array-order-wins, matching PipelineService::reorderStages):
 *   - the incoming `$orderedIds` array order is authoritative;
 *   - any `sort_order` the client sends is ignored — position N in the array
 *     becomes sort_order = N (1-based dense sequence);
 *   - every id MUST exist in the target table (optionally within a scope), else
 *     the whole update is rolled back with a 422.
 *
 * This is deliberately NOT scoped to a single domain — it is a cross-cutting
 * infra primitive owned by backend-architect, reused by any directory endpoint.
 */
final class SortOrderReorderer
{
    /**
     * Renumber `sort_order` on the given model's table to match `$orderedIds`.
     *
     * @param  class-string<Model>  $modelClass  Eloquent model whose table gets renumbered.
     * @param  array<int, int|string>  $orderedIds  Ids in their new display order.
     * @param  string  $errorKey  Validation error bag key for a bad id (e.g. 'items').
     * @param  array<string, mixed>  $scope  Optional extra WHERE constraints the ids must satisfy
     *                                       (e.g. ['entity_scope' => 'contact']).
     *
     * @throws ValidationException when any id is missing from the (scoped) table.
     */
    public static function apply(
        string $modelClass,
        array $orderedIds,
        string $errorKey = 'items',
        array $scope = [],
    ): void {
        DB::transaction(function () use ($modelClass, $orderedIds, $errorKey, $scope): void {
            /** @var Model $model */
            $model = new $modelClass;

            $existing = $modelClass::query()
                ->where($scope)
                ->lockForUpdate()
                ->pluck($model->getKeyName())
                ->flip();

            $position = 1;
            foreach ($orderedIds as $id) {
                if (! $existing->has($id)) {
                    throw ValidationException::withMessages([
                        $errorKey => "Item #{$id} does not exist in this directory.",
                    ])->status(422);
                }

                $modelClass::query()
                    ->whereKey($id)
                    ->update(['sort_order' => $position]);

                $position++;
            }
        });
    }
}
