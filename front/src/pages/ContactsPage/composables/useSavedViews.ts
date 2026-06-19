/**
 * Saved Views / Segments (localStorage-persisted until backend crm_saved_views is ready).
 *
 * A "view" stores: visibleColumns, sort, density, overlayFilters, search.
 * The system views "default" and "duplicates" are always present and non-deletable.
 */
import { ref, watch } from 'vue'
import type { ContactsDensity } from './useContactsView'
import type { ContactsOverlayFilters } from './useContactsFilters'

export interface SavedViewState {
  visibleFields: string[]
  sort: { field: string; direction: 'asc' | 'desc' } | null
  density: ContactsDensity
  filters: ContactsOverlayFilters
  search: string
}

export interface SavedView {
  id: string
  name: string
  type: 'personal' | 'team'
  state: SavedViewState
}

const STORAGE_KEY = 'mgcrm_contacts_saved_views_v1'

function loadFromStorage(): SavedView[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    return JSON.parse(raw) as SavedView[]
  } catch {
    return []
  }
}

function saveToStorage(views: SavedView[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(views))
  } catch {
    // quota exceeded — ignore silently
  }
}

export function useSavedViews() {
  const views = ref<SavedView[]>(loadFromStorage())

  watch(views, (v) => saveToStorage(v), { deep: true })

  function addView(name: string, type: SavedView['type'], state: SavedViewState): SavedView {
    const view: SavedView = {
      id: `view_${Date.now()}`,
      name,
      type,
      state,
    }
    views.value = [...views.value, view]
    return view
  }

  function removeView(id: string): void {
    // Cannot remove system views
    if (id === 'default' || id === 'duplicates') return
    views.value = views.value.filter((v) => v.id !== id)
  }

  function getViewState(id: string): SavedViewState | null {
    const view = views.value.find((v) => v.id === id)
    return view?.state ?? null
  }

  return {
    views,
    addView,
    removeView,
    getViewState,
  }
}
