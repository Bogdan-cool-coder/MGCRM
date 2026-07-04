/**
 * Motivation-card constructor (screen 2) data composable.
 *
 * Owns the whole editable form: employee + period selection, position toggles,
 * per-position plan rows, team-bonus rule, multi-currency base + auto-rates, and
 * the status machine. Server-state routes through `useMutation` / async loaders
 * (`api/motivation`); dirty tracking hooks into the Settings dirty-guard.
 *
 * Live ЗП-план total is computed on the frontend (SPEC save-bar) — sum of each
 * enabled position's `salary_plan_kopecks` (commission derived from % × plan).
 */
import { ref, reactive, computed, watch, inject, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useMutation } from '@/composables/async/useMutation'
import {
  getMotivationPlan,
  saveMotivationPlan,
  copyPreviousMonthPlan,
  finalizeMotivationCard,
  markMotivationCardPaid,
} from '@/api/motivation'
import { catalogApi } from '@/api/catalog'
import { salesApi } from '@/api/sales'
import { toKopecks, fromKopecks } from '@/utils/currency'
import {
  MK_POSITION_ORDER,
  type MotivationItemKind,
  type MotivationStatus,
  type MotivationPlan,
  type UpsertPlanPayload,
  type UpsertPlanItemPayload,
  type KpiType,
  type MkManagerOption,
  type MkTeamKpiRule,
} from '@/entities/motivation'
import {
  SETTINGS_MARK_DIRTY_KEY,
  SETTINGS_MARK_CLEAN_KEY,
} from '../../../composables/useSettings'

// ─── Local form models (display units, NOT kopecks) ───────────────────────────

export interface PlanRowForm {
  kind: MotivationItemKind
  name: string
  enabled: boolean
  /** plan amount in display units (money kinds) */
  planAmount: number | null
  currency: string
  /** commission % (0..100) */
  commissionPct: number | null
  /** KPI config */
  kpiType: KpiType
  kpiUnit: string
  kpiPlanCount: number | null
  kpiSalaryPerCompletion: number | null
  /** salary paid for this row (display units) */
  salaryPlan: number | null
}

export interface TeamRuleForm {
  pipelineId: number | null
  teamIncomeTarget: number | null
  targetCurrency: string
  basePool: number | null
  perExtraMember: number | null
  minMembers: number
  poolCurrency: string
  splitContributionPct: number
  minThresholdPct: number
}

export interface AutoRate {
  from: string
  to: string
  rate: number
  date: string
  source: string
}

const CURRENCIES = ['UZS', 'RUB', 'KZT', 'AED'] as const

const DEFAULT_POSITION_NAMES: Record<MotivationItemKind, string> = {
  base_salary: 'Оклад',
  commission: 'Комиссия',
  kpi: 'KPI',
  bonus: 'Бонус',
  team_kpi: 'Командный бонус',
}

/**
 * Static fallback pipelines the МК team-target can anchor on (contract §1.3 —
 * pipeline, not dept). Used only for instant first paint / when the pipelines
 * endpoint is unavailable; the live list is loaded from /api/pipelines and
 * exposed as the reactive `pipelineOptions` from useMotivationBuilder().
 */
export const PIPELINE_OPTIONS = [
  { label: 'MACRO Global', value: 1 },
  { label: 'MACRO AI Global', value: 2 },
]

const makeEmptyRow = (kind: MotivationItemKind, baseCurrency: string): PlanRowForm => ({
  kind,
  name: DEFAULT_POSITION_NAMES[kind],
  enabled: kind === 'base_salary' || kind === 'commission',
  planAmount: null,
  currency: baseCurrency,
  commissionPct: kind === 'commission' ? 10 : null,
  kpiType: 'count',
  kpiUnit: '',
  kpiPlanCount: null,
  kpiSalaryPerCompletion: null,
  salaryPlan: null,
})

export const useMotivationBuilder = () => {
  const { t } = useI18n()
  const toast = useToast()

  const markDirty = inject<() => void>(SETTINGS_MARK_DIRTY_KEY, () => {})
  const markClean = inject<() => void>(SETTINGS_MARK_CLEAN_KEY, () => {})

  // ─── Step 1: employee + period ───────────────────────────────────────────
  const now = new Date()
  const selectedEmployee = ref<MkManagerOption | null>(null)
  const year = ref<number>(now.getFullYear())
  const month = ref<number>(now.getMonth() + 1)

  const yearOptions = computed(() =>
    [0, 1, 2].map((d) => now.getFullYear() - d).map((y) => ({ label: String(y), value: y })),
  )

  // ─── Form state ──────────────────────────────────────────────────────────
  const baseCurrency = ref<string>('UZS')
  const loaded = ref(false)
  const existingCardId = ref<number | null>(null)
  const status = ref<MotivationStatus>('draft')

  const rows = reactive<PlanRowForm[]>(
    MK_POSITION_ORDER.map((k) => makeEmptyRow(k, baseCurrency.value)),
  )

  const teamRule = reactive<TeamRuleForm>({
    pipelineId: 1,
    teamIncomeTarget: null,
    targetCurrency: 'RUB',
    basePool: null,
    perExtraMember: null,
    minMembers: 2,
    poolCurrency: 'KZT',
    splitContributionPct: 60,
    minThresholdPct: 80,
  })

  /** Часть 2 — авто (100 − Часть 1). */
  const splitEqualPct = computed<number>(() => 100 - teamRule.splitContributionPct)

  /** Field-level mutator for the team rule (child emits, parent applies). */
  const updateTeamRule = <K extends keyof TeamRuleForm>(
    key: K,
    value: TeamRuleForm[K],
  ): void => {
    teamRule[key] = value
  }

  const currencyOptions = CURRENCIES.map((c) => ({ label: c, value: c }))

  // ─── Pipeline options (live, not hardcoded) ──────────────────────────────
  // Seed with the static fallback for instant paint, then replace with the real
  // pipelines from /api/pipelines so the team-target anchors on actual pipelines.
  const pipelineOptions = ref<{ label: string; value: number }[]>([...PIPELINE_OPTIONS])

  const loadPipelines = async (): Promise<void> => {
    try {
      const pipelines = await salesApi.getPipelines()
      const active = pipelines.filter((p) => p.is_active)
      if (active.length > 0) {
        pipelineOptions.value = active.map((p) => ({ label: p.name, value: p.id }))
      }
    } catch {
      // Keep the static fallback on failure.
    }
  }
  void loadPipelines()

  // ─── Auto exchange rates (read-only, ОВ-1) ───────────────────────────────
  const autoRates = ref<AutoRate[]>([])
  const ratesLoading = ref(false)

  const loadRates = async (): Promise<void> => {
    ratesLoading.value = true
    try {
      const res = await catalogApi.getExchangeRates({ to_code: baseCurrency.value, per_page: 50 })
      autoRates.value = res.data
        .filter((r) => r.to_code === baseCurrency.value && r.from_code !== baseCurrency.value)
        .map((r) => ({
          from: r.from_code,
          to: r.to_code,
          rate: r.rate,
          date: r.date,
          source: r.source,
        }))
    } catch {
      autoRates.value = []
    } finally {
      ratesLoading.value = false
    }
  }

  const refreshRates = async (): Promise<void> => {
    ratesLoading.value = true
    try {
      await catalogApi.refreshRates()
      await loadRates()
      toast.add({
        severity: 'success',
        summary: t('motivation.builder.rates_refreshed'),
        life: 3000,
      })
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      ratesLoading.value = false
    }
  }

  watch(baseCurrency, () => void loadRates())

  // ─── Live ЗП-план total (display kopecks) ─────────────────────────────────
  const commissionSalaryKopecks = (row: PlanRowForm): number => {
    if (row.planAmount == null || row.commissionPct == null) return 0
    return Math.round(toKopecks(row.planAmount) * (row.commissionPct / 100))
  }

  const rowSalaryPlanKopecks = (row: PlanRowForm): number => {
    if (!row.enabled) return 0
    switch (row.kind) {
      case 'commission':
        return commissionSalaryKopecks(row)
      case 'kpi':
        // count KPI: plan_count × salary_per_completion; else the row salary.
        if (row.kpiType === 'count' && row.kpiPlanCount != null && row.kpiSalaryPerCompletion != null) {
          return Math.round(row.kpiPlanCount * toKopecks(row.kpiSalaryPerCompletion))
        }
        return row.salaryPlan != null ? toKopecks(row.salaryPlan) : 0
      default:
        return row.salaryPlan != null
          ? toKopecks(row.salaryPlan)
          : row.planAmount != null
            ? toKopecks(row.planAmount)
            : 0
    }
  }

  const salaryPlanTotalKopecks = computed<number>(() =>
    rows.reduce((sum, r) => sum + rowSalaryPlanKopecks(r), 0),
  )

  // ─── Dirty tracking ──────────────────────────────────────────────────────
  // Hydration guard: applyPlan() mutates rows/teamRule, and the deep watch below
  // flushes on the NEXT tick — after applyPlan has already set loaded=true. Without
  // this flag the post-hydration watch fire would flip the card dirty on load.
  const hydrating = ref(false)
  const touch = (): void => {
    if (loaded.value && !hydrating.value) markDirty()
  }
  // Any form field change flips dirty (deep watch on the reactive rows/teamRule).
  watch([() => JSON.stringify(rows), () => JSON.stringify(teamRule), baseCurrency], touch)

  // ─── Load / hydrate ──────────────────────────────────────────────────────
  const applyPlan = (plan: MotivationPlan | null): void => {
    // Mark hydration in progress so the deep dirty-watch (which flushes next tick)
    // doesn't treat this programmatic mutation as a user edit.
    hydrating.value = true

    // Reset rows to defaults first (in place — rows are index-aligned to kinds).
    rows.forEach((row) => {
      Object.assign(row, makeEmptyRow(row.kind, baseCurrency.value))
    })
    existingCardId.value = plan?.id ?? null
    status.value = plan?.status ?? 'draft'

    if (!plan) {
      loaded.value = true
      markClean()
      void nextTick(() => { hydrating.value = false })
      return
    }

    baseCurrency.value = plan.base_currency
    teamRule.pipelineId = plan.pipeline_id ?? teamRule.pipelineId

    plan.items.forEach((item) => {
      const row = rows.find((r) => r.kind === item.kind)
      if (!row) return
      row.enabled = true
      row.name = item.name
      row.currency = item.currency
      row.planAmount = fromKopecks(item.plan_amount_kopecks)
      if (item.kind === 'commission') {
        row.commissionPct =
          item.params?.rate_pct_times_100 != null ? item.params.rate_pct_times_100 / 100 : null
      }
      if (item.kind === 'kpi') {
        row.kpiType = item.params?.kpi_type ?? 'count'
        row.kpiUnit = item.params?.unit ?? ''
        row.kpiPlanCount = item.params?.plan_count ?? null
        row.kpiSalaryPerCompletion =
          item.params?.salary_per_completion_kopecks != null
            ? fromKopecks(item.params.salary_per_completion_kopecks)
            : null
      }
    })

    if (plan.team_kpi_rule) {
      const r = plan.team_kpi_rule
      teamRule.pipelineId = r.pipeline_id
      teamRule.teamIncomeTarget = fromKopecks(r.team_income_target_kopecks)
      teamRule.targetCurrency = r.target_currency
      teamRule.basePool = fromKopecks(r.base_pool_kopecks)
      teamRule.perExtraMember = fromKopecks(r.per_extra_member_kopecks)
      teamRule.minMembers = r.min_members
      teamRule.poolCurrency = r.pool_currency
      teamRule.splitContributionPct = r.split_contribution_pct
      teamRule.minThresholdPct = r.min_threshold_pct
    }

    loaded.value = true
    markClean()
    void nextTick(() => { hydrating.value = false })
  }

  const loadState = useMutation<void>()

  const loadCard = async (): Promise<void> => {
    if (!selectedEmployee.value) return
    await loadState.run(
      async () => {
        const plan = await getMotivationPlan(selectedEmployee.value!.id, year.value, month.value)
        applyPlan(plan)
        await loadRates()
      },
      {
        onError: (err) => {
          const msg = err instanceof Error ? err.message : String(err)
          toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
        },
      },
    )
  }

  // ─── Build payload ───────────────────────────────────────────────────────
  const buildItems = (): UpsertPlanItemPayload[] =>
    rows
      .filter((r) => r.enabled)
      .map((r) => {
        const item: UpsertPlanItemPayload = {
          kind: r.kind,
          name: r.name,
          currency: r.currency,
          plan_amount_kopecks: r.planAmount != null ? toKopecks(r.planAmount) : 0,
        }
        if (r.kind === 'commission' && r.commissionPct != null) {
          item.params = { rate_pct_times_100: Math.round(r.commissionPct * 100) }
        } else if (r.kind === 'kpi') {
          item.params = {
            kpi_type: r.kpiType,
            unit: r.kpiUnit || undefined,
            plan_count: r.kpiType === 'count' ? (r.kpiPlanCount ?? undefined) : undefined,
            salary_per_completion_kopecks:
              r.kpiSalaryPerCompletion != null ? toKopecks(r.kpiSalaryPerCompletion) : undefined,
          }
        }
        return item
      })

  const buildTeamRule = (): MkTeamKpiRule | null => {
    if (!rows.find((r) => r.kind === 'team_kpi')?.enabled) return null
    return {
      pipeline_id: teamRule.pipelineId,
      team_income_target_kopecks:
        teamRule.teamIncomeTarget != null ? toKopecks(teamRule.teamIncomeTarget) : 0,
      target_currency: teamRule.targetCurrency,
      base_pool_kopecks: teamRule.basePool != null ? toKopecks(teamRule.basePool) : 0,
      per_extra_member_kopecks:
        teamRule.perExtraMember != null ? toKopecks(teamRule.perExtraMember) : 0,
      min_members: teamRule.minMembers,
      pool_currency: teamRule.poolCurrency,
      split_contribution_pct: teamRule.splitContributionPct,
      split_equal_pct: splitEqualPct.value,
      min_threshold_pct: teamRule.minThresholdPct,
    }
  }

  const buildPayload = (): UpsertPlanPayload => ({
    user_id: selectedEmployee.value!.id,
    year: year.value,
    month: month.value,
    pipeline_id: teamRule.pipelineId,
    base_currency: baseCurrency.value,
    items: buildItems(),
    team_kpi_rule: buildTeamRule(),
  })

  // ─── Validation ──────────────────────────────────────────────────────────
  const errors = reactive<Record<string, string>>({})

  const validate = (): boolean => {
    Object.keys(errors).forEach((k) => delete errors[k])
    if (!selectedEmployee.value) errors.employee = t('motivation.builder.err_employee')

    const base = rows.find((r) => r.kind === 'base_salary')
    if (base?.enabled) {
      if (base.planAmount == null || base.planAmount <= 0) errors.base = t('motivation.builder.err_base')
    }
    const commission = rows.find((r) => r.kind === 'commission')
    if (commission?.enabled) {
      if (commission.commissionPct == null || commission.commissionPct <= 0 || commission.commissionPct > 100) {
        errors.commission = t('motivation.builder.err_commission')
      }
    }
    const teamKpi = rows.find((r) => r.kind === 'team_kpi')
    if (teamKpi?.enabled) {
      if (teamRule.basePool == null || teamRule.basePool <= 0) errors.pool = t('motivation.builder.err_pool')
      if (teamRule.minThresholdPct <= 0 || teamRule.minThresholdPct > 100) {
        errors.threshold = t('motivation.builder.err_threshold')
      }
      if (teamRule.splitContributionPct <= 0 || teamRule.splitContributionPct >= 100) {
        errors.split = t('motivation.builder.err_split')
      }
    }
    return Object.keys(errors).length === 0
  }

  // ─── Save ────────────────────────────────────────────────────────────────
  const saveState = useMutation<MotivationPlan>()

  const save = async (): Promise<void> => {
    if (!validate()) {
      toast.add({ severity: 'error', summary: t('motivation.builder.err_form'), life: 4000 })
      return
    }
    await saveState.run(() => saveMotivationPlan(buildPayload()), {
      onSuccess: (plan) => {
        existingCardId.value = plan.id
        status.value = plan.status
        markClean()
        toast.add({ severity: 'success', summary: t('motivation.builder.saved_toast'), life: 3000 })
      },
      onError: (err) => {
        const msg = err instanceof Error ? err.message : String(err)
        toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
      },
    })
  }

  // ─── Copy previous month ─────────────────────────────────────────────────
  const copyPrevState = useMutation<void>()

  const copyPreviousMonth = async (): Promise<void> => {
    if (!selectedEmployee.value) return
    await copyPrevState.run(
      async () => {
        const plan = await copyPreviousMonthPlan(selectedEmployee.value!.id, year.value, month.value)
        if (!plan) {
          toast.add({ severity: 'info', summary: t('motivation.builder.no_prev_month'), life: 4000 })
          return
        }
        applyPlan(plan)
        markDirty() // copied data is unsaved for this month
      },
      {
        onError: (err) => {
          const msg = err instanceof Error ? err.message : String(err)
          toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
        },
      },
    )
  }

  // ─── Status transitions ──────────────────────────────────────────────────
  const transitionState = useMutation<void>()

  const runTransition = async (action: 'finalize' | 'mark-paid'): Promise<void> => {
    if (existingCardId.value == null) return
    await transitionState.run(
      async () => {
        const plan =
          action === 'finalize'
            ? await finalizeMotivationCard(existingCardId.value!)
            : await markMotivationCardPaid(existingCardId.value!)
        status.value = plan.status
        toast.add({
          severity: 'success',
          summary:
            action === 'finalize'
              ? t('motivation.builder.finalized_toast')
              : t('motivation.builder.paid_toast'),
          life: 3000,
        })
      },
      {
        onError: (err) => {
          const msg = err instanceof Error ? err.message : String(err)
          toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
        },
      },
    )
  }

  /** A finalized/paid card is read-only to the plan editor (contract §3.4). */
  const isReadOnly = computed<boolean>(() => status.value !== 'draft')

  /** True once a persisted card exists for this employee×period. */
  const cardExists = computed<boolean>(() => existingCardId.value != null)

  return {
    // step 1
    selectedEmployee,
    year,
    month,
    yearOptions,
    // form
    rows,
    teamRule,
    updateTeamRule,
    splitEqualPct,
    baseCurrency,
    currencyOptions,
    pipelineOptions,
    loaded,
    status,
    isReadOnly,
    cardExists,
    errors,
    // rates
    autoRates,
    ratesLoading,
    refreshRates,
    // totals
    salaryPlanTotalKopecks,
    // async flags
    loading: loadState.isPending,
    saving: saveState.isPending,
    copyingPrev: copyPrevState.isPending,
    transitioning: transitionState.isPending,
    // actions
    loadCard,
    save,
    copyPreviousMonth,
    finalize: () => runTransition('finalize'),
    markPaid: () => runTransition('mark-paid'),
  }
}
