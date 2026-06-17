import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { CompanyType, Source, Country, City, ContactPosition } from '@/entities/crm'
import { directoriesApi } from '@/api/crm/directories'

export const useDirectoriesStore = defineStore('directories', () => {
  // ─── State ──────────────────────────────────────────────────────────────────
  const companyTypes = ref<CompanyType[]>([])
  const sources = ref<Source[]>([])
  const countries = ref<Country[]>([])
  const cities = ref<City[]>([])
  const contactPositions = ref<ContactPosition[]>([])
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

  // ─── Actions ────────────────────────────────────────────────────────────────
  async function fetchAll(): Promise<void> {
    if (loaded.value || loading.value) return
    loading.value = true
    try {
      const [ct, src, cnt, cty, pos] = await Promise.all([
        directoriesApi.getCompanyTypes(),
        directoriesApi.getSources(),
        directoriesApi.getCountries(),
        directoriesApi.getCities(),
        directoriesApi.getContactPositions(),
      ])
      companyTypes.value = ct
      sources.value = src
      countries.value = cnt
      cities.value = cty
      contactPositions.value = pos
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
    // Actions
    fetchAll,
    fetchCitiesForCountry,
  }
})
