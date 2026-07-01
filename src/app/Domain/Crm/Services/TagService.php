<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

class TagService
{
    /**
     * Return tags for the admin settings page or autocomplete dropdowns.
     *
     * Scope logic:
     *   - If $scope is provided: return tags where scope = $scope OR scope IS NULL
     *     (universal tags are always included for any scope).
     *   - If $scope is null: return all tags (no scope filtering).
     *
     * @return Collection<int, Tag>
     */
    public function list(
        bool $activeOnly = false,
        ?string $scope = null,
        ?string $search = null,
    ): Collection {
        return Tag::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when(
                $scope !== null,
                fn ($q) => $q->where(fn ($inner) => $inner
                    ->where('scope', $scope)
                    ->orWhereNull('scope')
                )
            )
            ->when(
                $search !== null && $search !== '',
                fn ($q) => $q->where('name', 'like', '%'.$search.'%')
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new tag directory entry.
     */
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    /**
     * Update an existing tag entry.
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->update($data);

        return $tag->fresh();
    }

    /**
     * Delete a tag. Tags are soft in the sense that references are stored as
     * plain strings/arrays in jsonb — no FK guards needed. Hard delete is fine.
     */
    public function delete(Tag $tag): true
    {
        $tag->delete();

        return true;
    }
}
