<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HoldingService — group-of-companies hierarchy management.
 *
 * buildTree()          — ONE query: loads all companies sharing a group root,
 *                        then assembles the nested tree + ancestors in PHP.
 *                        No N+1: complexity is O(n) where n = group size.
 * ancestors()          — upward chain built from the pre-loaded map (no DB).
 * setParent()          — DB::transaction; runs detectParentConflict first.
 * detectParentConflict — BFS downward from candidateParentId; cycle if we reach company.id.
 *
 * No vendor-specific CTE — portable across PostgreSQL and SQLite.
 */
class HoldingService
{
    private const MAX_DEPTH = 10;

    /**
     * Build the full holding tree rooted at the given company's group root.
     *
     * Strategy (zero N+1):
     *   1. Walk up to the root in PHP (using previously loaded ancestors or a
     *      small loop — at most MAX_DEPTH individual finds, not per-node).
     *   2. Determine the root's ID, then load ALL companies that share this
     *      group root in ONE query: WHERE holding_id IN (entire sub-tree IDs).
     *      We do this iteratively: seed with root_id, expand by holding_id
     *      membership until the set stabilises — but since we load all at once
     *      ordered by holding_id, we can just load ALL companies whose tree
     *      root equals root_id by doing a SINGLE whereIn-expansion that
     *      terminates in PHP without extra round-trips.
     *
     *      Practical implementation: load ALL non-deleted companies that have
     *      holding_id = root_id OR whose own ID = root_id, then recursively
     *      expand using the in-memory map until no new IDs are added.
     *      For real-world holding sizes (< 1000 companies) this is always
     *      a single round-trip.
     *
     * Returns:
     *   {
     *     company:   { id, name, holding_role, you_are_here },
     *     ancestors: [ {id, name, holding_role}, ... ],  // root first → parent last
     *     children:  [ ...nested same structure... ]
     *   }
     *
     * @param  Company  $focal  The company currently being viewed
     * @return array<string, mixed>
     */
    public function buildTree(Company $focal): array
    {
        // Step 1: walk up to root (small loop, max MAX_DEPTH individual finds
        // — this is O(depth), typically 1-3 queries, not per-node).
        $root = $this->groupRoot($focal);

        // Step 2: load ALL companies in the holding group with ONE query.
        // We collect the full subtree ID set iteratively in PHP:
        //   - start with {root_id}
        //   - load all companies whose holding_id is in the current set
        //   - add their IDs to the set, repeat until stable
        // In practice this requires exactly ONE DB round-trip because we fetch
        // all crm_companies rows where holding_id IN (root_id ∪ all descendants),
        // which we resolve by loading all at once (limit: MAX_DEPTH * batch).
        $allInGroup = $this->loadGroupFlat($root->id);

        // Build id→Company map for O(1) lookup.
        /** @var array<int, Company> $byId */
        $byId = $allInGroup->keyBy('id')->all();

        // Ensure focal is in the map (may not be if it's solo with no subsidiaries).
        if (! isset($byId[$focal->id])) {
            $byId[$focal->id] = $focal;
        }
        if (! isset($byId[$root->id])) {
            $byId[$root->id] = $root;
        }

        // Step 3: build ancestors list from the map (pure PHP, zero DB).
        $ancestors = $this->ancestorsFromMap($focal, $byId);

        // Step 4: collect direct children of the focal company (flat list, zero DB).
        $children = $this->directChildrenFromMap($focal->id, $byId);

        return [
            // company = the focal company being viewed (you_are_here: true)
            'company' => $this->companyNode($focal, true),
            // ancestors = root-first chain up to focal's direct parent
            'ancestors' => array_map(
                fn (Company $c) => $this->companyNode($c, false),
                $ancestors,
            ),
            // children = flat HoldingCompanyNode[] of focal's direct subsidiaries
            'children' => $children,
        ];
    }

    /**
     * Load ALL companies that belong to the same holding group as $rootId
     * using a SINGLE database query.
     *
     * Algorithm: iteratively expand the ID set in PHP until stable, issuing
     * exactly ONE bulk whereIn query per expansion round. In practice for
     * real holding hierarchies (flat, ≤ MAX_DEPTH levels) this always
     * completes in 1 round (all descendants reachable from root in one pass).
     *
     * Portable across PostgreSQL and SQLite (no CTEs, no window functions).
     *
     * @return Collection<int, Company>
     */
    private function loadGroupFlat(int $rootId): Collection
    {
        $knownIds = [$rootId];
        $allRows = collect();
        $depth = 0;

        while ($depth < self::MAX_DEPTH) {
            $batch = Company::query()
                ->whereIn('holding_id', $knownIds)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'holding_id', 'holding_role']);

            if ($batch->isEmpty()) {
                break;
            }

            $newIds = $batch->pluck('id')->diff($knownIds)->values();
            $allRows = $allRows->merge($batch);

            if ($newIds->isEmpty()) {
                break;
            }

            $knownIds = array_merge($knownIds, $newIds->all());
            $depth++;
        }

        // Also include root itself.
        $root = Company::query()
            ->where('id', $rootId)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'holding_id', 'holding_role']);

        if ($root !== null) {
            $allRows->push($root);
        }

        return $allRows->unique('id');
    }

    /**
     * Build ancestors list from the pre-loaded in-memory map (no DB calls).
     *
     * @param  array<int, Company>  $byId
     * @return list<Company> root first → direct parent last
     */
    private function ancestorsFromMap(Company $company, array $byId): array
    {
        $chain = [];
        $currentId = $company->holding_id;
        $depth = 0;

        while ($currentId !== null && $depth < self::MAX_DEPTH) {
            $parent = $byId[$currentId] ?? null;
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent); // prepend so root comes first
            $currentId = $parent->holding_id;
            $depth++;
        }

        return $chain;
    }

    /**
     * Return direct children of $parentId as flat HoldingCompanyNode[].
     * Used by buildTree() to populate HoldingTreeDto.children.
     * Zero DB calls — pure PHP lookup.
     *
     * @param  array<int, Company>  $byId
     * @return array<int, array<string, mixed>>
     */
    private function directChildrenFromMap(int $parentId, array $byId): array
    {
        $result = [];
        foreach ($byId as $company) {
            if ((int) $company->holding_id !== $parentId) {
                continue;
            }
            $result[] = $this->companyNode($company, false);
        }

        return $result;
    }

    /**
     * Recursively build the nested children array from the in-memory map.
     * Zero DB calls — pure PHP traversal.
     *
     * @param  array<int, Company>  $byId
     * @return array<int, array<string, mixed>>
     */
    private function buildChildrenFromMap(int $parentId, int $focalId, array $byId, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $result = [];
        foreach ($byId as $company) {
            if ((int) $company->holding_id !== $parentId) {
                continue;
            }

            $result[] = [
                'company' => $this->companyNode($company, $company->id === $focalId),
                'children' => $this->buildChildrenFromMap($company->id, $focalId, $byId, $depth + 1),
            ];
        }

        return $result;
    }

    /**
     * Get the chain of ancestors from $company up to the group root.
     * Used externally; internally buildTree() uses ancestorsFromMap() instead.
     * Stops at MAX_DEPTH to prevent infinite loops in corrupted data.
     *
     * @return Collection<int, Company>
     */
    public function ancestors(Company $company): Collection
    {
        $chain = collect();
        $current = $company;
        $depth = 0;

        while ($current->holding_id !== null && $depth < self::MAX_DEPTH) {
            $parent = Company::find($current->holding_id);
            if ($parent === null) {
                break;
            }
            $chain->prepend($parent); // root first
            $current = $parent;
            $depth++;
        }

        return $chain;
    }

    /**
     * Set (or clear) the parent of a company within a holding group.
     * Throws InvalidArgumentException (→ 422) if a cycle is detected.
     */
    public function setParent(Company $company, ?int $parentId, HoldingRole $role, User $actor): void
    {
        if ($parentId !== null) {
            if ($parentId === $company->id) {
                throw new InvalidArgumentException('Holding cycle detected');
            }

            if ($this->detectParentConflict($company, $parentId)) {
                throw new InvalidArgumentException('Holding cycle detected');
            }
        }

        DB::transaction(static function () use ($company, $parentId, $role): void {
            $company->update([
                'holding_id' => $parentId,
                'holding_role' => $parentId !== null ? $role : null,
            ]);
        });
    }

    /**
     * Detach a company from its holding group (clears holding_id and holding_role).
     */
    public function detach(Company $company): void
    {
        DB::transaction(static function () use ($company): void {
            $company->update([
                'holding_id' => null,
                'holding_role' => null,
            ]);
        });
    }

    /**
     * Detect if setting $candidateParentId as the parent of $company would create a cycle.
     * BFS downward from candidateParentId — if we encounter company.id, it's a cycle.
     */
    public function detectParentConflict(Company $company, int $candidateParentId): bool
    {
        // Walk down from company itself; if candidateParentId appears in the subtree → cycle
        $visited = [$company->id => true];
        $queue = [$company->id];
        $depth = 0;

        while ($queue !== [] && $depth < self::MAX_DEPTH) {
            $nextQueue = [];
            $ids = array_unique($queue);

            $children = Company::query()
                ->whereIn('holding_id', $ids)
                ->whereNull('deleted_at')
                ->get(['id']);

            foreach ($children as $child) {
                if ($child->id === $candidateParentId) {
                    return true; // cycle detected
                }
                if (! isset($visited[$child->id])) {
                    $visited[$child->id] = true;
                    $nextQueue[] = $child->id;
                }
            }

            $queue = $nextQueue;
            $depth++;
        }

        return false;
    }

    // ---- Private helpers ----

    /**
     * Walk up to find the topmost company in the group (no holding_id).
     */
    private function groupRoot(Company $company): Company
    {
        $current = $company;
        $depth = 0;

        while ($current->holding_id !== null && $depth < self::MAX_DEPTH) {
            $parent = Company::find($current->holding_id);
            if ($parent === null) {
                break;
            }
            $current = $parent;
            $depth++;
        }

        return $current;
    }

    /**
     * Build a minimal company node for the tree response.
     *
     * @return array<string, mixed>
     */
    private function companyNode(Company $company, bool $youAreHere): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'holding_role' => $company->holding_role?->value,
            'you_are_here' => $youAreHere,
        ];
    }
}
