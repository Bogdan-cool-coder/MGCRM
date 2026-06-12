/**
 * Shared filters state for DealsPage (board + list views).
 * company is stored as a minimal ref (id + name) — not the full entity.
 */
import { ref } from 'vue'

export interface CompanyFilterRef {
  id: number
  name: string
}

export interface DealsFilters {
  q: string
  owner_id: number | null
  company: CompanyFilterRef | null
  stage_id: number | null
}

export function useDealsFilters(onFilterChange: () => void) {
  const filters = ref<DealsFilters>({
    q: '',
    owner_id: null,
    company: null,
    stage_id: null,
  })

  let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

  function onSearchInput() {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(() => {
      onFilterChange()
    }, 300)
  }

  function onFilterSelect() {
    onFilterChange()
  }

  function resetFilters() {
    filters.value = {
      q: '',
      owner_id: null,
      company: null,
      stage_id: null,
    }
    onFilterChange()
  }

  const hasActiveFilters = () => {
    return (
      !!filters.value.q ||
      filters.value.owner_id !== null ||
      filters.value.company !== null ||
      filters.value.stage_id !== null
    )
  }

  return {
    filters,
    onSearchInput,
    onFilterSelect,
    resetFilters,
    hasActiveFilters,
  }
}
