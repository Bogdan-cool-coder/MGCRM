<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Builder;

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
 * Filter + scope parity is structural, not copied: every counter is a COUNT off
 * a CLONE of DealService::kpiBaseQuery(), which is the exact scopedQuery() +
 * applyFilters() path that list()/board() use. The chips therefore count
 * byte-for-byte the same deals the list renders under identical filters; only the
 * pagination differs (KPI = whole funnel).
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
            'in_work' => $this->inWork($base),
            'cat_l' => $this->byCategory($base, [CategoryCode::L->value]),
            'cat_m' => $this->byCategory($base, [CategoryCode::M->value]),
            'cat_s' => $this->byCategory($base, [CategoryCode::S1->value, CategoryCode::S2->value]),
            'won' => $this->byStatus($base, isWon: true),
            'no_task' => $this->noTask($base),
            'overdue' => $this->overdue($base),
        ];
    }

    // ---- Private ----

    /**
     * Distinct companies among NON-won deals — the «В работе» chip. Two non-won
     * deals on the same company count once (matches the frontend's distinct count
     * of company.id over deals where stage.is_won === false).
     *
     * @param  Builder<Deal>  $base
     */
    private function inWork(Builder $base): int
    {
        return (int) (clone $base)
            ->whereHas('stage', static fn (Builder $s): Builder => $s->where('is_won', false))
            ->distinct()
            ->count('company_id');
    }

    /**
     * Deals on a company whose category_code is in the given set — the «Категории
     * L/M/S» chips. S-tier passes ['S1','S2'] so cat_s combines both sub-tiers.
     *
     * @param  Builder<Deal>  $base
     * @param  list<string>  $codes
     */
    private function byCategory(Builder $base, array $codes): int
    {
        return (int) (clone $base)
            ->whereHas('company', static fn (Builder $c): Builder => $c->whereIn('category_code', $codes))
            ->count();
    }

    /**
     * Deals whose current stage carries the given is_won flag — the «Выиграно»
     * chip (is_won = true). Uses the stage relation so it composes with the scoped
     * base query exactly like applyStatusFilter() does for the list.
     *
     * @param  Builder<Deal>  $base
     */
    private function byStatus(Builder $base, bool $isWon): int
    {
        return (int) (clone $base)
            ->whereHas('stage', static fn (Builder $s): Builder => $s->where('is_won', $isWon))
            ->count();
    }

    /**
     * Deals with no open next task — the «Без задачи» chip. Mirrors the list's
     * only_no_task preset (whereDoesntHave('nextTask')) so KPI and list agree on
     * what "no task" means (open, task-like, has a due_at).
     *
     * @param  Builder<Deal>  $base
     */
    private function noTask(Builder $base): int
    {
        return (int) (clone $base)->whereDoesntHave('nextTask')->count();
    }

    /**
     * Deals whose soonest open task is overdue (due_at < now) — the «Просрочено»
     * chip. Mirrors the list's only_overdue preset exactly.
     *
     * @param  Builder<Deal>  $base
     */
    private function overdue(Builder $base): int
    {
        return (int) (clone $base)
            ->whereHas('nextTask', static fn (Builder $t): Builder => $t->where('due_at', '<', now()))
            ->count();
    }
}
