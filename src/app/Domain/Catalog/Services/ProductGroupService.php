<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProductGroupService — all business logic for catalog product groups.
 */
class ProductGroupService
{
    /** @param array<string, mixed> $filters */
    public function list(array $filters): Collection
    {
        return ProductGroup::query()
            ->when(! empty($filters['active_only']), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): ProductGroup
    {
        return ProductGroup::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(ProductGroup $group, array $data): ProductGroup
    {
        $group->update($data);
        $group->refresh();

        return $group;
    }

    public function delete(ProductGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            if ($group->products()->exists()) {
                abort(409, 'Cannot delete group with existing products.');
            }

            $group->delete();
        });
    }
}
