import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { catalogApi } from '@/api/catalog'
import { getApiErrorMessage } from '@/utils/errors'
import type { ExchangeRateDto, CatalogPaginatedResponse } from '@/entities/catalog'

const emptyPage = (): CatalogPaginatedResponse<ExchangeRateDto> => ({
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null },
})

export interface ExchangeRatesFilter {
  from_code: string | null
  to_code: string | null
  date_from: Date | null
  date_to: Date | null
}

const DEFAULT_FILTER: ExchangeRatesFilter = {
  from_code: null,
  to_code: null,
  date_from: null,
  date_to: null,
}

function dateToApiString(d: Date | null): string | null {
  if (!d) return null
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

export const useExchangeRatesPage = () => {
  const { t } = useI18n()
  const toast = useToast()

  const page = ref(1)
  const perPage = ref(50)
  const filter = ref<ExchangeRatesFilter>({ ...DEFAULT_FILTER })

  const ratesResource = useAsyncResource<CatalogPaginatedResponse<ExchangeRateDto>>(emptyPage)

  const loading = computed(() => ratesResource.loading.value)
  const rates = computed(() => ratesResource.data.value.data)
  const total = computed(() => ratesResource.data.value.meta.total)

  /** Latest rate date — to compute staleness */
  const latestDate = computed<Date | null>(() => {
    const data = ratesResource.data.value.data
    if (!data.length) return null
    const sorted = [...data].sort(
      (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
    )
    const first = sorted[0]
    if (!first) return null
    return new Date(first.date)
  })

  const isStale = computed(() => {
    if (!latestDate.value) return false
    const diff = Date.now() - latestDate.value.getTime()
    return diff > 24 * 60 * 60 * 1000
  })

  async function load() {
    const params = {
      page: page.value,
      per_page: perPage.value,
      from_code: filter.value.from_code ?? undefined,
      to_code: filter.value.to_code ?? undefined,
      date_from: dateToApiString(filter.value.date_from) ?? undefined,
      date_to: dateToApiString(filter.value.date_to) ?? undefined,
    }
    try {
      await ratesResource.run(() => catalogApi.getExchangeRates(params))
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('catalog.exchangeRates.page.empty.title'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  function applyFilter() {
    page.value = 1
    void load()
  }

  function resetFilter() {
    filter.value = { ...DEFAULT_FILTER }
    page.value = 1
    void load()
  }

  function onPageChange(event: { page: number; rows: number }) {
    page.value = event.page + 1
    perPage.value = event.rows
    void load()
  }

  return {
    page,
    perPage,
    filter,
    loading,
    rates,
    total,
    latestDate,
    isStale,
    load,
    applyFilter,
    resetFilter,
    onPageChange,
  }
}
