import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { CompanyType, Source, Country, City, ContactPosition, AcquisitionChannel, DisconnectReason } from '@/entities/crm'
import { directoriesApi } from '@/api/crm/directories'

export const useDirectoriesStore = defineStore('directories', () => {
  // ─── State ──────────────────────────────────────────────────────────────────
  const companyTypes = ref<CompanyType[]>([])
  const sources = ref<Source[]>([])
  const countries = ref<Country[]>([])
  const cities = ref<City[]>([])
  const contactPositions = ref<ContactPosition[]>([])
  const acquisitionChannels = ref<AcquisitionChannel[]>([])
  const disconnectReasons = ref<DisconnectReason[]>([])
  const loaded = ref(false)
  const loading = ref(false)

  // ─── Getters ────────────────────────────────────────────────────────────────
  const getCompanyTypeLabel = computed(
    () =>
      (id: number | null | undefined): string => {
        if (!id) return ''
        return companyTypes.value.find((t) => t.id === id)?.name ?? ''
      },
  )

  const getSourceLabel = computed(
    () =>
      (code: string | null | undefined): string => {
        if (!code) return ''
        return sources.value.find((s) => s.code === code)?.name ?? code
      },
  )

  const getCountryName = computed(
    () =>
      (code: string | null | undefined): string => {
        if (!code) return ''
        return countries.value.find((c) => c.code === code)?.name ?? code
      },
  )

  const getCitiesForCountry = computed(
    () =>
      (countryCode: string | null | undefined): City[] => {
        if (!countryCode) return cities.value
        return cities.value.filter((c) => c.country_code === countryCode)
      },
  )

  const activeCompanyTypes = computed(() => companyTypes.value.filter((t) => t.is_active))
  const activeSources = computed(() => sources.value.filter((s) => s.is_active))
  const activeCountries = computed(() => countries.value.filter((c) => c.is_active))
  const activeContactPositions = computed(() => contactPositions.value.filter((p) => p.is_active))
  const activeAcquisitionChannels = computed(() => acquisitionChannels.value.filter((c) => c.is_active))
  const activeDisconnectReasons = computed(() => disconnectReasons.value.filter((r) => r.is_active))

  const getAcquisitionChannelName = computed(
    () =>
      (id: number | null | undefined): string => {
        if (!id) return ''
        return acquisitionChannels.value.find((c) => c.id === id)?.name ?? ''
      },
  )

  // ─── Actions ────────────────────────────────────────────────────────────────
  async function fetchAll(): Promise<void> {
    if (loaded.value || loading.value) return
    loading.value = true
    try {
      const [ct, src, cnt, cty, pos, acq, dr] = await Promise.all([
        directoriesApi.getCompanyTypes(),
        directoriesApi.getSources(),
        directoriesApi.getCountries(),
        directoriesApi.getCities(),
        directoriesApi.getContactPositions(),
        directoriesApi.getAcquisitionChannels({ active_only: true }),
        directoriesApi.getDisconnectReasons({ active_only: true }),
      ])
      companyTypes.value = ct
      sources.value = src
      countries.value = cnt
      cities.value = cty
      contactPositions.value = pos
      acquisitionChannels.value = acq
      disconnectReasons.value = dr
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  async function fetchCitiesForCountry(countryCode: string): Promise<void> {
    const existing = cities.value.some((c) => c.country_code === countryCode)
    if (existing) return
    const fetched = await directoriesApi.getCities(countryCode)
    const other = cities.value.filter((c) => c.country_code !== countryCode)
    cities.value = [...other, ...fetched]
  }

  return {
    // State
    companyTypes,
    sources,
    countries,
    cities,
    contactPositions,
    acquisitionChannels,
    disconnectReasons,
    loaded,
    loading,
    // Getters
    getCompanyTypeLabel,
    getSourceLabel,
    getCountryName,
    getCitiesForCountry,
    activeCompanyTypes,
    activeSources,
    activeCountries,
    activeContactPositions,
    activeAcquisitionChannels,
    activeDisconnectReasons,
    getAcquisitionChannelName,
    // Actions
    fetchAll,
    fetchCitiesForCountry,
  }
})
