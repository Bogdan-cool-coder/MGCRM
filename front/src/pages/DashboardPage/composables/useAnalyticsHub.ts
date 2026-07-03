/**
 * Analytics hub shell composable — active tab + cross-cutting filters.
 *
 * Owns the shell state the tabs share:
 *   - active tab   (`?tab=`, default `overview`; empty query = overview)
 *   - period       (month-stepper `year`/`month` + granularity month|year)
 *   - layer        (`?layer=`, operative|annual — affects Plans + plan-columns)
 *   - pipeline     (funnel filter)
 *   - manager      (admin/director cross-user filter)
 *
 * Pattern: `?tab=` mirrors `ManagerCabinetPage/index.vue`; filters live in one
 * source so every tab reads from here (TZ §1.1). The «Планы» tab is gated by
 * `plans.manage` — it is absent from `tabOptions` for a manager, and a hand-typed
 * `?tab=plans` without the right redirects to `overview`.
 *
 * Supplementary data (pipelines/managers) is loaded here so all tabs share one
 * pipeline pre-select — identical logic to `useDashboardPage` (kept for the
 * overview tab which still uses its own composable for widget data).
 */
import { ref, computed, watch, onMounted, type InjectionKey } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useUserStore } from '@/stores/user'
import { usePlanPermissions } from '@/composables/usePlanPermissions'
import { salesApi } from '@/api/sales'
import { usersApi } from '@/api/users'
import type { PipelineDto } from '@/entities/sales'
import type { UserOptionDto } from '@/api/users'
import type { PlanLayer } from '@/entities/planTargets'

export type HubTab = 'overview' | 'plans' | 'registry' | 'schedule' | 'rating'

export type PeriodGranularity = 'month' | 'year'

export interface HubTabOption {
  value: HubTab
  label: string
}

/** A tab-registered leave guard: returns `false` to veto an in-hub tab switch. */
export type HubLeaveGuard = () => boolean | Promise<boolean>

/**
 * Provide/inject key: the shell provides `registerLeaveGuard`; a dirty-capable
 * tab (Планы) registers/clears its guard on mount/unmount so switching tabs via
 * the SelectButton strip prompts before losing unsaved input.
 */
export const HUB_REGISTER_LEAVE_GUARD: InjectionKey<
  (guard: HubLeaveGuard | null) => void
> = Symbol('hubRegisterLeaveGuard')

/**
 * Provide/inject key inside the «Планы» tab: an editable metric panel (Поступления
 * / Задачи) registers its leave-guard with `TabPlans`, which consults it on an
 * inner metric switch and forwards it to the hub guard for outer tab switches.
 */
export const PLANS_REGISTER_METRIC_GUARD: InjectionKey<
  (guard: HubLeaveGuard | null) => void
> = Symbol('plansRegisterMetricGuard')

export const useAnalyticsHub = () => {
  const { t } = useI18n()
  const toast = useToast()
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()
  const { canManagePlans } = usePlanPermissions()

  // ─── Active tab (URL `?tab=`) ──────────────────────────────────────────────
  const VALID_TABS: readonly HubTab[] = ['overview', 'plans', 'registry', 'schedule', 'rating']

  const activeTab = computed<HubTab>(() => {
    const q = route.query.tab
    const tab = (typeof q === 'string' && (VALID_TABS as readonly string[]).includes(q)
      ? q
      : 'overview') as HubTab
    // Право-гейт: без plans.manage таб «Планы» недоступен — редирект в overview.
    if (tab === 'plans' && !canManagePlans.value) return 'overview'
    return tab
  })

  const tabOptions = computed<HubTabOption[]>(() => {
    const opts: HubTabOption[] = [
      { value: 'overview', label: t('dashboard.hub.tab_overview') },
    ]
    if (canManagePlans.value) {
      opts.push({ value: 'plans', label: t('dashboard.hub.tab_plans') })
    }
    opts.push(
      { value: 'registry', label: t('dashboard.hub.tab_registry') },
      { value: 'schedule', label: t('dashboard.hub.tab_schedule') },
      { value: 'rating', label: t('dashboard.hub.tab_rating') },
    )
    return opts
  })

  // ─── In-hub leave guard (dirty tabs veto a tab switch) ─────────────────────
  // A tab (e.g. «Планы») registers a guard that returns `false` to VETO a switch
  // (after prompting the user). Route-level navigation is already covered by the
  // tab's own onBeforeRouteLeave; this closes the gap for SelectButton switches
  // WITHIN the hub, which do not trigger a route change.
  const leaveGuard = ref<HubLeaveGuard | null>(null)

  const registerLeaveGuard = (guard: HubLeaveGuard | null): void => {
    leaveGuard.value = guard
  }

  /**
   * Switch tabs. Returns `false` when the switch was VETOED by the outgoing
   * tab's leave guard (dirty → user chose «Остаться») so the shell can force the
   * SelectButton to re-render: PrimeVue toggles its internal checked-state
   * optimistically before this async guard resolves, so on a veto the strip
   * would visually stick on the clicked tab while the content stays put.
   */
  const setActiveTab = async (tab: HubTab): Promise<boolean> => {
    if (tab === activeTab.value) return true
    // Ask the outgoing tab whether it is safe to leave (dirty-guard).
    if (leaveGuard.value) {
      const ok = await leaveGuard.value()
      if (!ok) return false
    }
    const query = { ...route.query }
    if (tab === 'overview') delete query.tab
    else query.tab = tab
    void router.replace({ query })
    return true
  }

  /** Report-style tabs surface the Excel export button (not overview/plans). */
  const showExport = computed<boolean>(() =>
    (['registry', 'schedule', 'rating'] as HubTab[]).includes(activeTab.value),
  )

  // ─── Period (month-stepper: year + month + granularity) ────────────────────
  const now = new Date()
  const year = ref<number>(now.getFullYear())
  const month = ref<number>(now.getMonth() + 1)
  const granularity = ref<PeriodGranularity>('month')

  const stepPeriod = (dir: -1 | 1): void => {
    if (granularity.value === 'year') {
      year.value += dir
      return
    }
    const next = month.value + dir
    if (next < 1) {
      month.value = 12
      year.value -= 1
    } else if (next > 12) {
      month.value = 1
      year.value += 1
    } else {
      month.value = next
    }
  }

  const setGranularity = (g: PeriodGranularity): void => {
    granularity.value = g
  }

  /**
   * Set the shared period year directly (used by the Рейтинг year-selector, which
   * is annual-granularity). Reuses the same `year` ref every tab reads, so the
   * hub period stays a single source of truth (§1.1) instead of a tab-local year.
   */
  const setYear = (value: number): void => {
    year.value = value
  }

  // ─── Layer (URL `?layer=`) ─────────────────────────────────────────────────
  const layer = computed<PlanLayer>(() =>
    route.query.layer === 'annual' ? 'annual' : 'operative',
  )

  const setLayer = (value: PlanLayer): void => {
    const query = { ...route.query }
    if (value === 'operative') delete query.layer
    else query.layer = value
    void router.replace({ query })
  }

  // ─── Pipeline + manager filters ────────────────────────────────────────────
  const pipelineId = ref<number | null>(null)
  const managerId = ref<number | null>(null)

  const pipelines = ref<PipelineDto[]>([])
  const managers = ref<UserOptionDto[]>([])
  const pipelinesLoading = ref(false)

  const canSeeAllManagers = computed<boolean>(
    () =>
      userStore.getUserRole != null &&
      ['admin', 'director'].includes(userStore.getUserRole),
  )

  const setPipelineId = (v: number | null): void => {
    pipelineId.value = v
    // Changing funnel drops the manager filter (matches useDashboardPage).
    managerId.value = null
  }

  const setManagerId = (v: number | null): void => {
    managerId.value = v
  }

  const loadPipelines = async (): Promise<void> => {
    pipelinesLoading.value = true
    try {
      const data = await salesApi.getPipelines()
      pipelines.value = data
      const preselect = data.find((p) => p.is_active) ?? data[0]
      if (preselect != null && pipelineId.value == null) {
        pipelineId.value = preselect.id
      }
    } catch {
      toast.add({
        severity: 'warn',
        summary: t('dashboard.pipelinesLoadError'),
        life: 4000,
      })
    } finally {
      pipelinesLoading.value = false
    }
  }

  const loadManagers = async (): Promise<void> => {
    if (!canSeeAllManagers.value) return
    try {
      const data = await usersApi.getUsers()
      managers.value = data.filter((u) =>
        ['admin', 'director', 'manager'].includes(u.role),
      )
    } catch {
      toast.add({
        severity: 'warn',
        summary: t('dashboard.managersLoadError'),
        life: 4000,
      })
    }
  }

  // ─── Sanitise a hand-typed ?tab=plans without the right ─────────────────────
  // If the user lands on ?tab=plans (or it was deep-linked) and later loses the
  // permission, strip the query so the URL matches the resolved overview tab.
  watch(canManagePlans, (can) => {
    if (!can && route.query.tab === 'plans') {
      const query = { ...route.query }
      delete query.tab
      void router.replace({ query })
    }
  })

  // Normalise the URL when the resolved tab differs from the raw `?tab=` query
  // (e.g. a hand-typed `?tab=plans` without the right, or `?tab=bogus`): strip
  // the stale query so the URL always reflects the tab actually rendered.
  const normalizeTabQuery = (): void => {
    const raw = typeof route.query.tab === 'string' ? route.query.tab : undefined
    const resolved = activeTab.value
    if (resolved === 'overview' && raw != null) {
      const query = { ...route.query }
      delete query.tab
      void router.replace({ query })
    } else if (resolved !== 'overview' && raw !== resolved) {
      void router.replace({ query: { ...route.query, tab: resolved } })
    }
  }

  onMounted(async () => {
    normalizeTabQuery()
    await loadPipelines()
    await loadManagers()
  })

  return {
    // tab
    activeTab,
    tabOptions,
    setActiveTab,
    registerLeaveGuard,
    showExport,
    // period
    year,
    month,
    granularity,
    stepPeriod,
    setGranularity,
    setYear,
    // layer
    layer,
    setLayer,
    // filters
    pipelineId,
    managerId,
    setPipelineId,
    setManagerId,
    pipelines,
    managers,
    pipelinesLoading,
    canSeeAllManagers,
    canManagePlans,
  }
}
