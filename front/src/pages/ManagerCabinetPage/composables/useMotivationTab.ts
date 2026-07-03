/**
 * Motivation-tab data composable (cabinet screen 1).
 *
 * Owns: period (year/month via URL query `?year=&month=`), the viewed user
 * (director/admin cross-user), the card async resource, and the 30s live poll
 * for the team-bonus forecast (SPEC ОВ-2). No raw axios in components —
 * everything routes through `useAsyncResource` + `api/motivation`.
 */
import { computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getMotivationCard } from '@/api/motivation'
import type { MotivationCard } from '@/entities/motivation'

const POLL_INTERVAL_MS = 30_000

const MONTH_LABEL_KEYS = [
  'january', 'february', 'march', 'april', 'may', 'june',
  'july', 'august', 'september', 'october', 'november', 'december',
] as const

export interface MonthOption {
  label: string
  value: string // "YYYY-M"
  year: number
  month: number
}

export const useMotivationTab = (viewedUserId: () => number | null) => {
  const { t } = useI18n()
  const toast = useToast()
  const route = useRoute()
  const router = useRouter()

  // ─── Period (from URL, defaults to current month) ──────────────────────────
  const now = new Date()

  const period = computed<{ year: number; month: number }>(() => {
    const qy = Number(route.query.year)
    const qm = Number(route.query.month)
    const year = Number.isFinite(qy) && qy > 2000 ? qy : now.getFullYear()
    const month = Number.isFinite(qm) && qm >= 1 && qm <= 12 ? qm : now.getMonth() + 1
    return { year, month }
  })

  const isCurrentMonth = computed<boolean>(
    () => period.value.year === now.getFullYear() && period.value.month === now.getMonth() + 1,
  )

  /** Last 12 months as Select options (label localised). */
  const monthOptions = computed<MonthOption[]>(() =>
    Array.from({ length: 12 }, (_, i) => {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
      const year = d.getFullYear()
      const month = d.getMonth() + 1
      const monthName = t(`motivation.card.months.${MONTH_LABEL_KEYS[month - 1]}`)
      return {
        label: `${monthName} ${year}`,
        value: `${year}-${month}`,
        year,
        month,
      }
    }),
  )

  const selectedMonthValue = computed<string>(
    () => `${period.value.year}-${period.value.month}`,
  )

  const setPeriod = (year: number, month: number): void => {
    void router.replace({
      query: { ...route.query, year: String(year), month: String(month) },
    })
  }

  const stepMonth = (delta: number): void => {
    const d = new Date(period.value.year, period.value.month - 1 + delta, 1)
    setPeriod(d.getFullYear(), d.getMonth() + 1)
  }

  const selectMonthByValue = (value: string): void => {
    const opt = monthOptions.value.find((o) => o.value === value)
    if (opt) setPeriod(opt.year, opt.month)
  }

  // ─── Card resource ─────────────────────────────────────────────────────────
  const cardResource = useAsyncResource<MotivationCard | null>(() => null)

  const loadCard = async (silent = false): Promise<void> => {
    try {
      await cardResource.run(() =>
        getMotivationCard({
          year: period.value.year,
          month: period.value.month,
          user_id: viewedUserId() ?? undefined,
        }),
      )
    } catch (err) {
      if (silent) return
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    }
  }

  // ─── 30s live poll (forecast freshness, ОВ-2) ──────────────────────────────
  let pollTimer: ReturnType<typeof setInterval> | null = null

  const startPoll = (): void => {
    stopPoll()
    pollTimer = setInterval(() => void loadCard(true), POLL_INTERVAL_MS)
  }

  const stopPoll = (): void => {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  // ─── Reactions ─────────────────────────────────────────────────────────────
  watch(
    () => [period.value.year, period.value.month, viewedUserId()],
    () => void loadCard(),
  )

  onMounted(() => {
    void loadCard()
    startPoll()
  })

  onBeforeUnmount(stopPoll)

  return {
    card: cardResource.data,
    loading: cardResource.loading,
    error: cardResource.error,
    period,
    isCurrentMonth,
    monthOptions,
    selectedMonthValue,
    stepMonth,
    selectMonthByValue,
    reload: () => loadCard(),
  }
}
