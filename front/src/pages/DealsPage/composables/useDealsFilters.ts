/**
 * Shared filters state for DealsPage (board + list views).
 * Extended for Kanban 2.0 redesign — overlay filters + presets.
 */
import { ref, computed } from 'vue'
import type { OverlayFilters } from '../components/DealsFilterOverlay.vue'

export interface DealsFilters {
  q: string
  owner_id: number | null
  owner_ids: number[]
  stage_id: number | null
  stage_ids: number[]
  status: 'open' | 'won' | 'lost' | null
  only_mine: boolean
  only_no_task: boolean
  only_overdue: boolean
  dateRange: Date[] | null
  product_q: string
  region: string
  city: string
  budget_from: number | null
  budget_to: number | null
  tags: string[]
}

function emptyFilters(): DealsFilters {
  return {
    q: '',
    owner_id: null,
    owner_ids: [],
    stage_id: null,
    stage_ids: [],
    status: null,
    only_mine: false,
    only_no_task: false,
    only_overdue: false,
    dateRange: null,
    product_q: '',
    region: '',
    city: '',
    budget_from: null,
    budget_to: null,
    tags: [],
  }
}

export function useDealsFilters(onFilterChange: () => void) {
  const filters = ref<DealsFilters>(emptyFilters())

  // Overlay open state
  const filterOverlayVisible = ref(false)

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
    filters.value = emptyFilters()
    onFilterChange()
  }

  function applyOverlayFilters(overlayFilters: OverlayFilters) {
    filters.value = {
      q: overlayFilters.q,
      owner_id: overlayFilters.owner_ids[0] ?? null,
      owner_ids: overlayFilters.owner_ids,
      stage_id: overlayFilters.stage_ids[0] ?? null,
      stage_ids: overlayFilters.stage_ids,
      status: overlayFilters.status,
      only_mine: overlayFilters.only_mine,
      only_no_task: overlayFilters.only_no_task,
      only_overdue: overlayFilters.only_overdue,
      dateRange: overlayFilters.dateRange,
      product_q: overlayFilters.product_q,
      region: overlayFilters.region,
      city: overlayFilters.city,
      budget_from: overlayFilters.budget_from,
      budget_to: overlayFilters.budget_to,
      tags: overlayFilters.tags,
    }
    filterOverlayVisible.value = false
    onFilterChange()
  }

  function toOverlayFilters(): OverlayFilters {
    return {
      q: filters.value.q,
      dateRange: filters.value.dateRange,
      stage_ids: filters.value.stage_ids,
      owner_ids: filters.value.owner_ids,
      product_q: filters.value.product_q,
      region: filters.value.region,
      city: filters.value.city,
      budget_from: filters.value.budget_from,
      budget_to: filters.value.budget_to,
      tags: filters.value.tags,
      status: filters.value.status,
      only_mine: filters.value.only_mine,
      only_no_task: filters.value.only_no_task,
      only_overdue: filters.value.only_overdue,
    }
  }

  const hasActiveFilters = () => {
    const f = filters.value
    return (
      !!f.q ||
      f.owner_id !== null ||
      f.owner_ids.length > 0 ||
      f.stage_id !== null ||
      f.stage_ids.length > 0 ||
      f.status !== null ||
      f.only_mine ||
      f.only_no_task ||
      f.only_overdue ||
      !!f.product_q ||
      !!f.region ||
      !!f.city ||
      f.budget_from !== null ||
      f.budget_to !== null ||
      f.tags.length > 0
    )
  }

  const activeFilterCount = computed(() => {
    const f = filters.value
    let n = 0
    if (f.q) n++
    if (f.owner_ids.length) n++
    if (f.stage_ids.length) n++
    if (f.status) n++
    if (f.only_mine) n++
    if (f.only_no_task) n++
    if (f.only_overdue) n++
    if (f.product_q) n++
    if (f.region || f.city) n++
    if (f.budget_from !== null || f.budget_to !== null) n++
    if (f.tags.length) n++
    return n
  })

  return {
    filters,
    filterOverlayVisible,
    onSearchInput,
    onFilterSelect,
    resetFilters,
    applyOverlayFilters,
    toOverlayFilters,
    hasActiveFilters,
    activeFilterCount,
  }
}
