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
 * tree()               — BFS downward: company → children → grandchildren (MAX_DEPTH=10).
 *                        Returns nested array structure for the front-end HoldingTree.vue.
 * ancestors()          — upward chain in PHP loop (MAX_DEPTH iterations).
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
     * If the company belongs to a group (holding_id set), walk up to the root first.
     *
     * Returns:
     *   {
     *     company:   { id, name, holding_role, you_are_here },
     *     ancestors: [ {id, name, holding_role}, ... ],  // root first → parent last
     *     children:  [ ...nested same structure... ]
     *   }
     *
     * Always returns a valid structure (empty children if not in any group).
     *
     * @param  Company  $focal  The company currently being viewed
     * @return array<string, mixed>
     */
    public function buildTree(Company $focal): array
    {
        // Walk up to find the group root
        $root = $this->groupRoot($focal);
        $ancestors = $this->ancestors($focal);

        // If not in any group, return minimal "solo" tree
        if ($root->id === $focal->id && $root->holding_id === null) {
            $subs = $focal->subsidiaries()->get();
            if ($subs->isEmpty()) {
                return [
                    'company' => $this->companyNode($focal, true),
                    'ancestors' => [],
                    'children' => [],
                ];
            }
        }

        $children = $this->bfsDown($root, $focal->id, 0);

        return [
            'company' => $this->companyNode($root, $root->id === $focal->id),
            'ancestors' => $ancestors->map(fn (Company $c) => $this->companyNode($c, $c->id === $focal->id))->values()->all(),
            'children' => $children,
        ];
    }

    /**
     * Get the chain of ancestors from $company up to the group root.
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
     * BFS downward from $root, building the nested children array.
     * Marks $focalId as you_are_here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bfsDown(Company $parent, int $focalId, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $children = $parent->subsidiaries()->whereNull('deleted_at')->get();

        return $children->map(function (Company $child) use ($focalId, $depth): array {
            return [
                'company' => $this->companyNode($child, $child->id === $focalId),
                'children' => $this->bfsDown($child, $focalId, $depth + 1),
            ];
        })->values()->all();
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
