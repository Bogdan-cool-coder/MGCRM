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
                fn ($q) => $q->whereLikeCi('name', $search)
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

    /**
     * Permanently purge ALL tag directory entries.
     *
     * Used ONLY by the system-reset `directories` category
     * (`docs/contracts/system-reset-api-contract.md` §1 row 9). Called by
     * `App\Support\System\SystemResetService` — never invoked directly from a
     * controller. No authorization check here: the caller is the single
     * admin-gated entry point (`can:system-reset`).
     *
     * `crm_tags` has no FK dependents — tag names are stored as plain strings
     * inside `crm_contacts.tags` / `crm_companies.tags` (jsonb arrays), never
     * referenced by FK. A single bulk delete is safe and order-independent.
     *
     * Must run inside the caller's own transaction (contract §5) — this
     * method does NOT open one of its own.
     *
     * @return array<string, int> counts keyed by table name
     */
    public function purgeAll(): array
    {
        $count = Tag::query()->count();
        Tag::query()->delete();

        return ['crm_tags' => $count];
    }
}
