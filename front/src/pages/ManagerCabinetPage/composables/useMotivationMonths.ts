/**
 * Shared motivation month state (period + options), backed by the URL query
 * `?year=&month=`. Extracted from useMotivationTab so the CabinetToolbar month
 * dropdown can drive the same period without instantiating a second card fetch
 * / poll (ManagerCabinet-v2 §2.4).
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'

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

export const useMotivationMonths = () => {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()

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

  /** Last 12 months as options (label localised). */
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

  const selectedValue = computed<string>(() => `${period.value.year}-${period.value.month}`)

  const setPeriod = (year: number, month: number): void => {
    void router.replace({
      query: { ...route.query, year: String(year), month: String(month) },
    })
  }

  const stepMonth = (delta: number): void => {
    const d = new Date(period.value.year, period.value.month - 1 + delta, 1)
    setPeriod(d.getFullYear(), d.getMonth() + 1)
  }

  const setMonthByValue = (value: string): void => {
    const opt = monthOptions.value.find((o) => o.value === value)
    if (opt) setPeriod(opt.year, opt.month)
  }

  return {
    period,
    isCurrentMonth,
    monthOptions,
    selectedValue,
    setPeriod,
    stepMonth,
    setMonthByValue,
  }
}
