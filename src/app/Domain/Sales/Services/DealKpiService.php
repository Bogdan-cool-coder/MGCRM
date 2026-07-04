<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * DealKpiService — funnel-wide KPI counters for the Deals page chip bar
 * (SalesFunnel-spec §5.1). Powers GET /api/deals/kpi.
 *
 * The frontend's DealsKpiChips.vue computes every chip as a Vue computed() over
 * only the CURRENTLY LOADED list page (default per_page 25), so the chips
 * silently undercount whenever the funnel spans more than one page. This service
 * recomputes the SAME chips across the WHOLE filtered, visibility-scoped funnel —
 * pagination is never applied.
 *
 * Filter + scope parity is structural, not copied: every counter is computed off
 * a CLONE of DealService::kpiBaseQuery(), which is the exact scopedQuery() +
 * applyFilters() path that list()/board() use. The chips therefore count
 * byte-for-byte the same deals the list renders under identical filters; only the
 * pagination differs (KPI = whole funnel).
 *
 * Data-Layer-Audit-2026-07 §3.5: the seven per-chip ->count() round-trips are
 * folded into a SINGLE conditional-aggregate scan of the scoped base, mirroring
 * the repo reference ActivityService::countsByPreset() (lift each chip's WHERE
 * fragment into a CASE column). Every counter stays byte-identical to the old
 * per-chip COUNT — the predicates are the SAME whereHas/whereDoesntHave clauses,
 * only evaluated once per row instead of once per chip.
 *
 * Chip definitions (mirror DealsKpiChips.vue):
 *   in_work — DISTINCT company_id among deals whose stage.is_won = false
 *   cat_l   — deals on a company with category_code = 'L'
 *   cat_m   — deals on a company with category_code = 'M'
 *   cat_s   — deals on a company with category_code IN ('S1','S2')  (S-tier combined)
 *   won     — deals whose stage.is_won = true
 *   no_task — deals with no open next task (whereDoesntHave('nextTask'))
 *   overdue — deals whose nextTask.due_at < now
 *
 * All counters respect the same filters + row-level visibility scope baked into
 * the base query (the scope is resolved by ResolveVisibility in the controller).
 */
class DealKpiService
{
    public function __construct(
        private readonly DealService $deals,
    ) {}

    /**
     * Funnel-wide KPI counters for the Deals page.
     *
     * pipeline_id defaults to the active sales pipeline when the request omits it,
     * so the KPI scopes to the same board the page renders (the board view always
     * fixes a pipeline). The resolved id is echoed back in the payload so the
     * frontend can confirm which funnel was counted.
     *
     * @param  array<string, mixed>  $filters
     * @return array{pipeline_id: ?int, in_work: int, cat_l: int, cat_m: int, cat_s: int, won: int, no_task: int, overdue: int}
     */
    public function forFunnel(array $filters, VisibilityScope $scope, User $user): array
    {
        // Default to the page's funnel when pipeline_id is absent (the board always
        // fixes a pipeline) so KPI counts match exactly what the page shows.
        if (! isset($filters['pipeline_id'])) {
            $defaultId = $this->deals->defaultSalesPipelineId();

            if ($defaultId !== null) {
                $filters['pipeline_id'] = $defaultId;
            }
        }

        $base = $this->deals->kpiBaseQuery($filters, $scope, $user);

        return [
            'pipeline_id' => isset($filters['pipeline_id']) ? (int) $filters['pipeline_id'] : null,
            ...$this->aggregate($base),
        ];
    }

    // ---- Private ----

    /**
     * Compute all seven chip counters in a single conditional-aggregate scan of
     * the scoped base. Each chip's predicate is lifted (WHERE SQL + bindings) from
     * a throwaway Deal builder into a CASE column, exactly as
     * ActivityService::countsByPreset() does — so the numbers are byte-identical to
     * the previous per-chip ->count() calls while the DB is scanned once.
     *
     * `in_work` is a DISTINCT company count, not a row count: it uses
     * COUNT(DISTINCT CASE WHEN <not-won> THEN COALESCE(company_id, -id) END). The
     * COALESCE(company_id, -id) key keeps a company-less open deal counted once on
     * its own (deal ids are positive, real company ids positive, so the negated-id
     * space never collides) — matching the previous distinct-count semantics (#4).
     *
     * @param  Builder<Deal>  $base
     * @return array{in_work: int, cat_l: int, cat_m: int, cat_s: int, won: int, no_task: int, overdue: int}
     */
    private function aggregate(Builder $base): array
    {
        $selects = [];
        $bindings = [];

        // in_work — DISTINCT company among non-won deals (see method docblock).
        $notWon = $this->predicate(fn (Builder $q) => $q->whereHas('stage', static fn (Builder $s): Builder => $s->where('is_won', false)));
        $selects[] = 'count(distinct case when '.$notWon['sql'].' then coalesce(deals.company_id, -deals.id) end) as in_work';
        $bindings = array_merge($bindings, $notWon['bindings']);

        // cat_l / cat_m / cat_s — row counts by the company's category_code.
        foreach ([
            'cat_l' => [CategoryCode::L->value],
            'cat_m' => [CategoryCode::M->value],
            'cat_s' => [CategoryCode::S1->value, CategoryCode::S2->value],
        ] as $alias => $codes) {
            $cat = $this->predicate(fn (Builder $q) => $q->whereHas('company', static fn (Builder $c): Builder => $c->whereIn('category_code', $codes)));
            [$selects, $bindings] = $this->addCountCase($selects, $bindings, $alias, $cat);
        }

        // won — deals whose current stage is a win.
        $won = $this->predicate(fn (Builder $q) => $q->whereHas('stage', static fn (Builder $s): Builder => $s->where('is_won', true)));
        [$selects, $bindings] = $this->addCountCase($selects, $bindings, 'won', $won);

        // no_task — deals with no open next task (whereDoesntHave('nextTask')).
        $noTask = $this->predicate(fn (Builder $q) => $q->whereDoesntHave('nextTask'));
        [$selects, $bindings] = $this->addCountCase($selects, $bindings, 'no_task', $noTask);

        // overdue — deals whose soonest open task is overdue (due_at < now).
        $overdue = $this->predicate(fn (Builder $q) => $q->whereHas('nextTask', static fn (Builder $t): Builder => $t->where('due_at', '<', now())));
        [$selects, $bindings] = $this->addCountCase($selects, $bindings, 'overdue', $overdue);

        /** @var object|null $row */
        $row = (clone $base)->selectRaw(implode(', ', $selects), $bindings)->first();

        return [
            'in_work' => (int) ($row?->in_work ?? 0),
            'cat_l' => (int) ($row?->cat_l ?? 0),
            'cat_m' => (int) ($row?->cat_m ?? 0),
            'cat_s' => (int) ($row?->cat_s ?? 0),
            'won' => (int) ($row?->won ?? 0),
            'no_task' => (int) ($row?->no_task ?? 0),
            'overdue' => (int) ($row?->overdue ?? 0),
        ];
    }

    /**
     * Append a `sum(case when <predicate> then 1 else 0 end) as <alias>` column
     * plus its bindings to the running select/binding lists.
     *
     * @param  list<string>  $selects
     * @param  list<mixed>  $bindings
     * @param  array{sql: string, bindings: list<mixed>}  $predicate
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function addCountCase(array $selects, array $bindings, string $alias, array $predicate): array
    {
        $selects[] = 'sum(case when '.$predicate['sql'].' then 1 else 0 end) as '.$alias;

        return [$selects, array_merge($bindings, $predicate['bindings'])];
    }

    /**
     * Build a chip predicate on a throwaway Deal builder and lift its WHERE clause
     * into a raw SQL fragment (+ bindings) suitable for embedding in a CASE
     * expression. The SoftDeletes global scope is stripped so the fragment carries
     * ONLY the chip predicate (the base query already excludes deleted rows); each
     * whereHas emits a self-contained correlated EXISTS subquery.
     *
     * @param  callable(Builder<Deal>): mixed  $apply
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function predicate(callable $apply): array
    {
        $query = Deal::query()->withoutGlobalScope(SoftDeletingScope::class);
        $apply($query);

        $sql = $query->toSql();
        $pos = stripos($sql, ' where ');

        return [
            'sql' => $pos === false ? '1=1' : '('.substr($sql, $pos + strlen(' where ')).')',
            'bindings' => $query->getBindings(),
        ];
    }
}
