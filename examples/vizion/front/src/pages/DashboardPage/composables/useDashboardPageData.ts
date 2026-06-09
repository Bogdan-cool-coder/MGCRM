import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { getLocalizedText } from '@/utils/localization'
import type { Dashboard, DashboardData } from '@/entities/dashboard'
import type { PeriodRange } from '@/api/types/dashboards'
import { defaultRange, normaliseRange, rangesEqual } from './periodRange'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

const MONTH_KEY = /^\d{4}-(0[1-9]|1[0-2])$/

/** First `YYYY-MM` query value, or `null` if absent / malformed. */
const queryMonth = (value: unknown): string | null => {
  const raw = Array.isArray(value) ? value[0] : value
  return typeof raw === 'string' && MONTH_KEY.test(raw) ? raw : null
}

/**
 * Resolve the initial range from the URL. Supports the current
 * `?period_from=&period_to=` pair and the legacy single `?period=YYYY-MM`
 * (= a one-month range). Falls back to the last-12-months default.
 */
const initialRange = (query: Record<string, unknown>): PeriodRange => {
  const from = queryMonth(query.period_from)
  const to = queryMonth(query.period_to)
  if (from && to) return normaliseRange(from, to)
  const legacy = queryMonth(query.period)
  if (legacy) return { from: legacy, to: legacy }
  return defaultRange()
}

export const useDashboardPageData = () => {
  const route = useRoute()
  const router = useRouter()
  const { t, locale } = useLocalI18n({ en, ru })
  const { notifyApiError } = useNotifications()
  const { dashboardService } = useServices()

  const dashboardId = computed<number>(() => {
    const raw = route.params.id
    const id = Number(Array.isArray(raw) ? raw[0] : raw)
    return Number.isFinite(id) && id > 0 ? id : 0
  })

  const dashboard = ref<Dashboard | null>(null)
  const loading = ref(false)
  const data = ref<DashboardData | null>(null)
  const isLoadingData = ref(false)
  const period = ref<PeriodRange>(initialRange(route.query))

  const localizedTitle = computed(() =>
    dashboard.value ? getLocalizedText(dashboard.value.name, locale.value) : '',
  )

  const loadDashboard = async () => {
    if (dashboardId.value <= 0) return
    loading.value = true
    try {
      dashboard.value = await dashboardService.fetchDashboard(dashboardId.value)
    } catch (error) {
      notifyApiError(error, t('errors.loadFailed'), t('common.error'))
      dashboard.value = null
    } finally {
      loading.value = false
    }
  }

  const loadData = async () => {
    if (dashboardId.value <= 0) return
    isLoadingData.value = true
    try {
      data.value = await dashboardService.fetchDashboardData(dashboardId.value, period.value)
    } catch (error) {
      notifyApiError(error, t('errors.loadDataFailed'), t('common.error'))
      data.value = null
    } finally {
      isLoadingData.value = false
    }
  }

  /** Mirror the active range into the URL so it can be shared / restored. */
  const syncRangeToUrl = (range: PeriodRange) => {
    // Drop the legacy single-month param once we own the range pair.
    const { period: _legacy, ...rest } = route.query
    void router.replace({
      query: { ...rest, period_from: range.from, period_to: range.to },
    })
  }

  const reloadAll = async () => {
    await loadDashboard()
    await loadData()
  }

  // Fetch on id resolution (guard against id=0 mount race) — mirrors the
  // report-page pattern of `watch(id, { immediate: true })` with a falsy guard.
  watch(
    dashboardId,
    (id) => {
      if (id > 0) void reloadAll()
    },
    { immediate: true },
  )

  // Range changes refetch only the data (layout / widget set unchanged).
  // Debounced so dragging across several months in the picker fires one
  // request, and mirrored into the URL for shareable / restorable state.
  let refetchTimer: ReturnType<typeof setTimeout> | null = null
  watch(
    period,
    (next, prev) => {
      if (rangesEqual(next, prev)) return
      syncRangeToUrl(next)
      if (refetchTimer) clearTimeout(refetchTimer)
      refetchTimer = setTimeout(() => {
        refetchTimer = null
        if (dashboardId.value > 0) void loadData()
      }, 350)
    },
  )

  return {
    t,
    locale,
    dashboardId,
    dashboard,
    loading,
    data,
    isLoadingData,
    period,
    localizedTitle,
    loadDashboard,
    loadData,
    reloadAll,
  }
}
