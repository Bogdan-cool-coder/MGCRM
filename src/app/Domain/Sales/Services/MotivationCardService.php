<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Catalog\Services\ExchangeRateService;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\KpiFilters;
use App\Domain\Sales\Data\TeamBonusShares;
use App\Domain\Sales\Enums\FactSourceKind;
use App\Domain\Sales\Enums\MotivationCardItemKind;
use App\Domain\Sales\Enums\MotivationCardStatus;
use App\Domain\Sales\Exceptions\IllegalMotivationStatusTransitionException;
use App\Domain\Sales\Exceptions\MotivationCardLockedException;
use App\Domain\Sales\Models\MotivationCard;
use App\Domain\Sales\Models\MotivationCardItem;
use App\Domain\Sales\Models\TeamKpiRule;
use App\Domain\Sales\Services\FactSource\FactSource;
use App\Domain\Sales\Services\FactSource\FactSourceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MotivationCardService — CRUD orchestration + compute engine for the
 * Motivation Card (МК, contract §4.1).
 *
 * The compute engine is pure where possible (docs/backend-standard.md §5):
 * commissionKopecks / teamBonusPoolKopecks / teamBonusShares / gatePassed take
 * no DB dependency and are directly unit-testable. Money is integer kopecks
 * throughout; #DIV/0 guards return 0 everywhere a denominator can be zero.
 */
class MotivationCardService
{
    public function __construct(
        private readonly ManagerKpiService $kpiService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly FactSourceResolver $factSourceResolver,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD / constructor
    // -------------------------------------------------------------------------

    public function findForConstructor(int $userId, int $year, int $month): ?MotivationCard
    {
        return MotivationCard::query()
            ->where('user_id', $userId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->with('items')
            ->first();
    }

    /**
     * Create-or-update the whole card (header + items + team rule) atomically.
     * Idempotent by (user_id, year, month). Rejects writes to a finalized/paid
     * card (409 via MotivationCardLockedException).
     *
     * @param  array<string, mixed>  $data  validated StoreMotivationPlanRequest payload
     */
    public function upsertPlan(array $data, User $actor): MotivationCard
    {
        return DB::transaction(function () use ($data) {
            $card = MotivationCard::query()
                ->where('user_id', $data['user_id'])
                ->where('period_year', $data['year'])
                ->where('period_month', $data['month'])
                ->lockForUpdate()
                ->first();

            if ($card !== null && $card->status !== MotivationCardStatus::Draft) {
                throw new MotivationCardLockedException;
            }

            if ($card === null) {
                $card = new MotivationCard;
                $card->user_id = $data['user_id'];
                $card->period_year = $data['year'];
                $card->period_month = $data['month'];
                $card->status = MotivationCardStatus::Draft;
            }

            $card->pipeline_id = $data['pipeline_id'] ?? null;
            $card->base_currency = $data['base_currency'];
            $card->supervisor_user_id = $data['supervisor_user_id'] ?? null;
            $card->fact_source = FactSourceKind::WonDeals;
            $card->save();

            // Replace items wholesale — simplest idempotent strategy for a
            // constructor form that always submits the full row set.
            $card->items()->delete();

            foreach ($data['items'] as $index => $itemData) {
                MotivationCardItem::create([
                    'motivation_card_id' => $card->id,
                    'kind' => $itemData['kind'],
                    'name' => $itemData['name'],
                    'plan_amount_kopecks' => $itemData['plan_amount_kopecks'] ?? 0,
                    'fact_amount_kopecks' => $itemData['fact_amount_kopecks'] ?? 0,
                    'salary_plan_kopecks' => $itemData['salary_plan_kopecks'] ?? 0,
                    'salary_fact_kopecks' => $itemData['salary_fact_kopecks'] ?? 0,
                    'currency' => $itemData['currency'],
                    'params' => $itemData['params'] ?? [],
                    'sort' => $itemData['sort'] ?? $index,
                ]);
            }

            if (isset($data['team_kpi_rule'])) {
                $this->upsertTeamKpiRule($data['team_kpi_rule'], $data['year'], $data['month']);
            }

            return $card->fresh('items');
        });
    }

    /**
     * @param  array<string, mixed>  $ruleData
     */
    private function upsertTeamKpiRule(array $ruleData, int $year, int $month): TeamKpiRule
    {
        $rule = TeamKpiRule::query()
            ->where('pipeline_id', $ruleData['pipeline_id'])
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first() ?? new TeamKpiRule([
                'pipeline_id' => $ruleData['pipeline_id'],
                'period_year' => $year,
                'period_month' => $month,
            ]);

        $rule->fill([
            'team_income_target_kopecks' => $ruleData['team_income_target_kopecks'] ?? 0,
            'target_currency' => $ruleData['target_currency'] ?? 'RUB',
            'base_pool_kopecks' => $ruleData['base_pool_kopecks'] ?? 0,
            'per_extra_member_kopecks' => $ruleData['per_extra_member_kopecks'] ?? 0,
            'min_members' => $ruleData['min_members'] ?? 2,
            'pool_currency' => $ruleData['pool_currency'] ?? 'RUB',
            'split_contribution_pct' => $ruleData['split_contribution_pct'] ?? 60,
            'split_equal_pct' => $ruleData['split_equal_pct'] ?? 40,
            'min_threshold_pct' => $ruleData['min_threshold_pct'] ?? 80,
        ]);
        $rule->save();

        return $rule;
    }

    /**
     * Copy prior-month plan structure into (year, month) as a draft.
     * Returns null if no prior card exists.
     */
    public function copyFromPreviousMonth(int $userId, int $year, int $month): ?MotivationCard
    {
        $prevDate = Carbon::create($year, $month, 1)->subMonthNoOverflow();
        $prev = $this->findForConstructor($userId, (int) $prevDate->year, (int) $prevDate->month);

        if ($prev === null) {
            return null;
        }

        return DB::transaction(function () use ($prev, $userId, $year, $month) {
            $existing = MotivationCard::query()
                ->where('user_id', $userId)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();

            if ($existing !== null && $existing->status !== MotivationCardStatus::Draft) {
                throw new MotivationCardLockedException;
            }

            $card = $existing ?? new MotivationCard;
            $card->user_id = $userId;
            $card->period_year = $year;
            $card->period_month = $month;
            $card->status = MotivationCardStatus::Draft;
            $card->pipeline_id = $prev->pipeline_id;
            $card->base_currency = $prev->base_currency;
            $card->supervisor_user_id = $prev->supervisor_user_id;
            $card->fact_source = FactSourceKind::WonDeals;
            $card->save();

            $card->items()->delete();

            foreach ($prev->items as $item) {
                MotivationCardItem::create([
                    'motivation_card_id' => $card->id,
                    'kind' => $item->kind,
                    'name' => $item->name,
                    'plan_amount_kopecks' => $item->plan_amount_kopecks,
                    'fact_amount_kopecks' => 0, // fact never carries over
                    'salary_plan_kopecks' => $item->salary_plan_kopecks,
                    'salary_fact_kopecks' => 0,
                    'currency' => $item->currency,
                    'params' => $item->params,
                    'sort' => $item->sort,
                ]);
            }

            $prevRule = $prev->pipeline_id !== null
                ? TeamKpiRule::query()
                    ->where('pipeline_id', $prev->pipeline_id)
                    ->where('period_year', $prev->period_year)
                    ->where('period_month', $prev->period_month)
                    ->first()
                : null;

            if ($prevRule !== null) {
                $this->upsertTeamKpiRule([
                    'pipeline_id' => $prevRule->pipeline_id,
                    'team_income_target_kopecks' => $prevRule->team_income_target_kopecks,
                    'target_currency' => $prevRule->target_currency,
                    'base_pool_kopecks' => $prevRule->base_pool_kopecks,
                    'per_extra_member_kopecks' => $prevRule->per_extra_member_kopecks,
                    'min_members' => $prevRule->min_members,
                    'pool_currency' => $prevRule->pool_currency,
                    'split_contribution_pct' => $prevRule->split_contribution_pct,
                    'split_equal_pct' => $prevRule->split_equal_pct,
                    'min_threshold_pct' => $prevRule->min_threshold_pct,
                ], $year, $month);
            }

            return $card->fresh('items');
        });
    }

    // -------------------------------------------------------------------------
    // Status machine
    // -------------------------------------------------------------------------

    /**
     * Allowed edges (Phase A, contract §9 Q7): draft→finalized, finalized→paid.
     * Un-finalize (finalized→draft) is denied by default.
     */
    public function assertCanTransition(MotivationCardStatus $from, MotivationCardStatus $to): void
    {
        $allowed = [
            MotivationCardStatus::Draft->value => [MotivationCardStatus::Finalized],
            MotivationCardStatus::Finalized->value => [MotivationCardStatus::Paid],
            MotivationCardStatus::Paid->value => [],
        ];

        $targets = $allowed[$from->value] ?? [];

        if (! in_array($to, $targets, strict: true)) {
            throw new IllegalMotivationStatusTransitionException($from, $to);
        }
    }

    public function finalize(MotivationCard $card, User $actor): MotivationCard
    {
        $this->assertCanTransition($card->status, MotivationCardStatus::Finalized);

        $card->status = MotivationCardStatus::Finalized;
        $card->finalized_at = now();
        $card->finalized_by_user_id = $actor->id;
        $card->save();

        return $card;
    }

    public function markPaid(MotivationCard $card, User $actor): MotivationCard
    {
        $this->assertCanTransition($card->status, MotivationCardStatus::Paid);

        $card->status = MotivationCardStatus::Paid;
        $card->paid_at = now();
        $card->save();

        return $card;
    }

    // -------------------------------------------------------------------------
    // Cabinet read + compute
    // -------------------------------------------------------------------------

    /**
     * Full read payload for the manager cabinet (contract §6.1 shape).
     * Recomputes interim facts on read (Phase A has no hard freeze, §9 Q8).
     *
     * @return array<string, mixed>
     */
    public function buildCabinetPayload(int $userId, int $year, int $month, User $viewer): array
    {
        $target = User::query()->findOrFail($userId);
        $card = $this->findForConstructor($userId, $year, $month);
        $filters = KpiFilters::forMonth($year, $month, $userId);
        $baseCurrency = $card->base_currency ?? config('crm.currencies.default', 'RUB');
        $multiCurrencyWarning = false;

        if ($card === null) {
            return [
                'meta' => [
                    'user' => ['id' => $target->id, 'full_name' => $target->full_name, 'avatar_path' => $target->avatar_path],
                    'supervisor' => null,
                    'pipeline' => null,
                    'period' => ['year' => $year, 'month' => $month, 'label' => $filters->monthLabel()],
                    'status' => MotivationCardStatus::Draft->value,
                    'base_currency' => $baseCurrency,
                    'fact_source' => FactSourceKind::WonDeals->value,
                    'multi_currency_warning' => false,
                    'has_card' => false,
                ],
                'dept_plan' => ['target_kopecks' => 0, 'target_currency' => $baseCurrency, 'fact_kopecks' => 0, 'pct' => null, 'badge' => 'none'],
                'items' => [],
                'total' => ['salary_plan_kopecks' => 0, 'salary_fact_kopecks' => 0, 'currency' => $baseCurrency],
                'team_bonus_forecast' => null,
                'rates' => ['date' => now()->startOfMonth()->toDateString(), 'source' => 'api', 'pairs' => []],
            ];
        }

        $factSource = $this->factSourceResolver->for($card->fact_source);
        $ratesDate = Carbon::create($year, $month, 1)->toDateString();

        $teamRule = $card->pipeline_id !== null
            ? TeamKpiRule::query()
                ->where('pipeline_id', $card->pipeline_id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first()
            : null;

        // ---- Team fact (dept plan block + gate) ----
        $teamFactKopecks = 0;
        $userContribution = 0;
        $teamContributions = [];
        $nMembers = 0;

        if ($teamRule !== null) {
            $memberIds = $this->pipelineMemberIds((int) $card->pipeline_id, $year, $month);
            $nMembers = count($memberIds);
            $teamContributions = $factSource->teamContributions($memberIds, (int) $card->pipeline_id, $filters, $teamRule->target_currency, $multiCurrencyWarning);
            $teamFactKopecks = array_sum($teamContributions);
            $userContribution = $teamContributions[$userId] ?? 0;
        }

        $deptPlan = [
            'target_kopecks' => $teamRule->team_income_target_kopecks ?? 0,
            'target_currency' => $teamRule->target_currency ?? $baseCurrency,
            'fact_kopecks' => $teamFactKopecks,
            'pct' => $teamRule !== null ? $this->kpiService->scorePct($teamFactKopecks, $teamRule->team_income_target_kopecks) : null,
            'badge' => $teamRule !== null ? $this->kpiService->scoreBadge($this->kpiService->scorePct($teamFactKopecks, $teamRule->team_income_target_kopecks)) : 'none',
        ];

        // ---- Items ----
        $items = [];
        $totalPlan = 0;
        $totalFact = 0;

        foreach ($card->items as $item) {
            [$row, $planKop, $factKop] = $this->computeItemRow($item, $card->user_id, $factSource, $filters, $baseCurrency, $ratesDate, $multiCurrencyWarning);
            $items[] = $row;
            $totalPlan += $planKop;
            $totalFact += $factKop;
        }

        // ---- Team bonus forecast (live, contract §4.5) ----
        $teamBonusForecast = null;

        if ($teamRule !== null) {
            $gatePassed = $this->gatePassed($teamFactKopecks, $teamRule->team_income_target_kopecks, $teamRule->min_threshold_pct);
            $pool = $this->teamBonusPoolKopecks($teamRule->base_pool_kopecks, $nMembers, $teamRule->per_extra_member_kopecks, $teamRule->min_members);

            $shares = $gatePassed
                ? $this->teamBonusShares($pool, $teamRule->split_contribution_pct, $teamRule->split_equal_pct, $nMembers, $userContribution, $teamFactKopecks)
                : new TeamBonusShares(0, 0);

            $convertedPart1 = $this->convertOrWarn($shares->part1Kopecks, $teamRule->pool_currency, $baseCurrency, $ratesDate, $multiCurrencyWarning);
            $convertedPart2 = $this->convertOrWarn($shares->part2Kopecks, $teamRule->pool_currency, $baseCurrency, $ratesDate, $multiCurrencyWarning);

            $teamBonusForecast = [
                'gate_passed' => $gatePassed,
                'dept_pct' => $deptPlan['pct'],
                'threshold_pct' => $teamRule->min_threshold_pct,
                'part1_kopecks' => $convertedPart1,
                'part2_kopecks' => $convertedPart2,
                'total_kopecks' => $convertedPart1 + $convertedPart2,
                'currency' => $baseCurrency,
            ];
        }

        return [
            'meta' => [
                'user' => ['id' => $target->id, 'full_name' => $target->full_name, 'avatar_path' => $target->avatar_path],
                'supervisor' => $card->supervisor_user_id !== null
                    ? ['id' => $card->supervisor?->id, 'full_name' => $card->supervisor?->full_name]
                    : null,
                'pipeline' => $card->pipeline_id !== null
                    ? ['id' => $card->pipeline?->id, 'name' => $card->pipeline?->name]
                    : null,
                'period' => ['year' => $year, 'month' => $month, 'label' => $filters->monthLabel()],
                'status' => $card->status->value,
                'base_currency' => $baseCurrency,
                'fact_source' => $card->fact_source->value,
                'multi_currency_warning' => $multiCurrencyWarning,
                'has_card' => true,
            ],
            'dept_plan' => $deptPlan,
            'items' => $items,
            'total' => [
                'salary_plan_kopecks' => $totalPlan,
                'salary_fact_kopecks' => $totalFact,
                'currency' => $baseCurrency,
            ],
            'team_bonus_forecast' => $teamBonusForecast,
            'rates' => $this->ratesBlock($card, $teamRule, $ratesDate),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int, 2: int} [row, salary_plan_kopecks, salary_fact_kopecks]
     */
    private function computeItemRow(
        MotivationCardItem $item,
        int $cardUserId,
        FactSource $factSource,
        KpiFilters $filters,
        string $baseCurrency,
        string $ratesDate,
        bool &$multiCurrencyWarning,
    ): array {
        $params = $item->params ?? [];

        $factAmountKopecks = $item->fact_amount_kopecks;

        // Phase A: commission facts are filled from won-deal income on read if
        // not manually overridden (manual entry always wins, contract §6.3).
        // $cardUserId is passed in by the caller (audit fix, 2026-07-04):
        // reading $item->card?->user_id here lazy-loaded a fresh `card`
        // relation PER ITEM (N+1 — the caller already holds the card) and
        // silently fell back to a bogus user_id=0 income-fact lookup on any
        // cache/relation miss instead of failing loudly.
        if ($item->kind === MotivationCardItemKind::Commission && $factAmountKopecks === 0) {
            $personalIncome = $factSource->personalIncome($cardUserId, $filters, $item->currency, $multiCurrencyWarning);
            $factAmountKopecks = $personalIncome;
        }

        $salaryPlanKopecks = $item->salary_plan_kopecks;
        $salaryFactKopecks = $item->salary_fact_kopecks;

        // Fix #35: salary_plan was NEVER derived for any kind (only commission's
        // salary_fact was) — so an item saved with only plan_amount_kopecks filled
        // (the common constructor path) rendered salary_plan=0 in the cabinet total,
        // even though a real plan existed. Manual entry (storage != 0) always wins —
        // every branch below only fires when the stored column is still 0.
        if ($item->kind === MotivationCardItemKind::Commission) {
            $ratePctTimes100 = (int) ($params['rate_pct_times_100'] ?? 0);

            if ($salaryPlanKopecks === 0) {
                // Plan-side ЗП = rate × plan indicator (mirrors the fact-side formula).
                $salaryPlanKopecks = $this->commissionKopecks($item->plan_amount_kopecks, $ratePctTimes100);
            }

            if ($salaryFactKopecks === 0) {
                $salaryFactKopecks = $this->commissionKopecks($factAmountKopecks, $ratePctTimes100);
            }
        } elseif ($item->kind === MotivationCardItemKind::BaseSalary || $item->kind === MotivationCardItemKind::Bonus) {
            // Оклад / flat bonus is always paid in full — plan salary = plan
            // indicator; fact salary = plan indicator too (contract §6.1 example:
            // "Оклад" plan_amount == salary_plan == salary_fact when unpaid-yet).
            if ($salaryPlanKopecks === 0) {
                $salaryPlanKopecks = $item->plan_amount_kopecks;
            }

            if ($salaryFactKopecks === 0) {
                $salaryFactKopecks = $item->plan_amount_kopecks;
            }
        } elseif ($item->kind === MotivationCardItemKind::Kpi) {
            $kpiType = $params['kpi_type'] ?? 'count';

            if ($kpiType === 'count') {
                $planCount = (int) ($params['plan_count'] ?? 0);
                $perCompletion = (int) ($params['salary_per_completion_kopecks'] ?? 0);

                if ($salaryPlanKopecks === 0) {
                    $salaryPlanKopecks = $planCount * $perCompletion;
                }
            } elseif ($kpiType === 'amount') {
                // Money-denominated indicator: the plan salary IS the plan amount
                // (same "paid if plan is met" logic as base_salary/bonus).
                if ($salaryPlanKopecks === 0) {
                    $salaryPlanKopecks = $item->plan_amount_kopecks;
                }
            } elseif ($kpiType === 'manual') {
                // Binary done/not-done: the planned payout is the full completion
                // bonus (mirrors count's plan_count=1 case); fact only pays out
                // once manual_done is actually true.
                $perCompletion = (int) ($params['salary_per_completion_kopecks'] ?? 0);

                if ($salaryPlanKopecks === 0) {
                    $salaryPlanKopecks = $perCompletion;
                }

                if ($salaryFactKopecks === 0 && ($params['manual_done'] ?? false) === true) {
                    $salaryFactKopecks = $perCompletion;
                }
            }
        }

        $planConverted = $this->convertOrWarn($salaryPlanKopecks, $item->currency, $baseCurrency, $ratesDate, $multiCurrencyWarning);
        $factConverted = $this->convertOrWarn($salaryFactKopecks, $item->currency, $baseCurrency, $ratesDate, $multiCurrencyWarning);

        $pct = $this->itemPct($item, $params, $factAmountKopecks);

        $row = [
            'kind' => $item->kind->value,
            'name' => $item->name,
            'sort' => $item->sort,
            'plan_amount_kopecks' => $item->plan_amount_kopecks,
            'fact_amount_kopecks' => $factAmountKopecks,
            'currency' => $item->currency,
            'salary_plan_kopecks' => $planConverted,
            'salary_fact_kopecks' => $factConverted,
            'pct' => $pct,
            'badge' => $this->kpiService->scoreBadge($pct),
            'params' => $params,
        ];

        return [$row, $planConverted, $factConverted];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function itemPct(MotivationCardItem $item, array $params, int $factAmountKopecks): ?int
    {
        if ($item->kind === MotivationCardItemKind::Kpi) {
            $kpiType = $params['kpi_type'] ?? 'count';

            if ($kpiType === 'manual') {
                return null; // manual KPIs render a done/not-done badge, not %
            }

            if ($kpiType === 'count') {
                $plan = (int) ($params['plan_count'] ?? 0);
                $fact = (int) ($params['fact_count'] ?? 0);

                return $this->kpiService->scorePct($fact, $plan);
            }
        }

        return $this->kpiService->scorePct($factAmountKopecks, $item->plan_amount_kopecks);
    }

    private function convertOrWarn(int $amountKopecks, string $from, string $to, string $date, bool &$multiCurrencyWarning): int
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $amountKopecks;
        }

        $converted = $this->exchangeRateService->convertAmount($amountKopecks, $from, $to, $date);

        if ($converted === null) {
            $multiCurrencyWarning = true;

            return $amountKopecks;
        }

        return $converted;
    }

    /**
     * @return list<int>
     */
    private function pipelineMemberIds(int $pipelineId, int $year, int $month): array
    {
        return MotivationCard::query()
            ->where('pipeline_id', $pipelineId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{date: string, source: string, pairs: list<array{from: string, to: string, rate: string}>}
     */
    private function ratesBlock(MotivationCard $card, ?TeamKpiRule $teamRule, string $ratesDate): array
    {
        $currencies = $card->items->pluck('currency')->push($card->base_currency);

        if ($teamRule !== null) {
            $currencies = $currencies->push($teamRule->pool_currency)->push($teamRule->target_currency);
        }

        $pairs = [];

        foreach ($currencies->unique()->values() as $currency) {
            if (strtoupper((string) $currency) === strtoupper($card->base_currency)) {
                continue;
            }

            $rate = $this->exchangeRateService->getRate((string) $currency, $card->base_currency, $ratesDate);

            if ($rate !== null) {
                $pairs[] = ['from' => strtoupper((string) $currency), 'to' => strtoupper($card->base_currency), 'rate' => $rate];
            }
        }

        return ['date' => $ratesDate, 'source' => 'api', 'pairs' => $pairs];
    }

    // -------------------------------------------------------------------------
    // Pure math helpers (contract §4.3) — unit-testable without DB.
    // -------------------------------------------------------------------------

    /**
     * commission = round(personalFactKopecks * rate_pct_times_100 / 10000)
     * (rate_pct_times_100=1000 → 10.00%).
     */
    public function commissionKopecks(int $personalFactKopecks, int $ratePctTimes100): int
    {
        if ($ratePctTimes100 === 0) {
            return 0;
        }

        return (int) round($personalFactKopecks * $ratePctTimes100 / 10000);
    }

    /**
     * pool = base_pool + max(0, n_members - min_members) * per_extra.
     */
    public function teamBonusPoolKopecks(int $basePool, int $nMembers, int $perExtra, int $minMembers = 2): int
    {
        $extra = max(0, $nMembers - $minMembers);

        return $basePool + $extra * $perExtra;
    }

    /**
     * 60/40 split for one team member.
     *   part1 (proportional) = pool * splitContribPct/100 * userContribution/teamTotal
     *   part2 (equal)        = pool * splitEqualPct/100 / nMembers
     * #DIV/0 guards: teamTotal=0 → part1=0; nMembers=0 → (0,0).
     */
    public function teamBonusShares(
        int $pool,
        int $splitContribPct,
        int $splitEqualPct,
        int $nMembers,
        int $userContribution,
        int $teamTotal,
    ): TeamBonusShares {
        if ($nMembers === 0) {
            return new TeamBonusShares(0, 0);
        }

        $proportionalPool = (int) round($pool * $splitContribPct / 100);
        $equalPool = (int) round($pool * $splitEqualPct / 100);

        $part1 = $teamTotal === 0
            ? 0
            : (int) round($proportionalPool * $userContribution / $teamTotal);

        $part2 = (int) round($equalPool / $nMembers);

        return new TeamBonusShares($part1, $part2);
    }

    /**
     * Gate: teamFact/teamTarget*100 >= minThresholdPct. teamTarget=0 → gate
     * FAILS (not #DIV/0 → not "passed").
     */
    public function gatePassed(int $teamFactKopecks, int $teamTargetKopecks, int $minThresholdPct): bool
    {
        if ($teamTargetKopecks === 0) {
            return false;
        }

        $pct = $teamFactKopecks / $teamTargetKopecks * 100;

        return $pct >= $minThresholdPct;
    }
}
