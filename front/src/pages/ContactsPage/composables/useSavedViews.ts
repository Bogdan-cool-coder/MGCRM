/**
 * Saved Views / Segments — server-backed (GET/POST/PATCH/DELETE /api/crm/saved-views).
 *
 * Source of truth: server.
 * localStorage (`mgcrm_saved_views_cache_v2_{entityType}`) is used as a fast-start
 * cache; it is replaced by the server response on every successful load.
 *
 * A "view" stores: columns, sort, density, filters (mapped to SavedViewPayload).
 * The local SavedView is a UI projection of SavedViewDto.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { savedViewsApi, type SavedViewDto, type SavedViewPayload } from '@/api/crm/savedViews'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactsDensity } from './useContactsView'
import type { ContactsOverlayFilters } from './useContactsFilters'
import type { EntityType } from './useContactsPageData'

// ── UI model ──────────────────────────────────────────────────────────────────

export interface SavedViewState {
  visibleFields: string[]
  sort: { field: string; direction: 'asc' | 'desc' } | null
  density: ContactsDensity
  filters: ContactsOverlayFilters
  search: string
}

export interface SavedView {
  /** numeric server id, or 'default'/'duplicates' for system views */
  id: string
  name: string
  type: 'personal' | 'team'
  isDefault: boolean
  ownerId: number | null
  state: SavedViewState
}

// ── Mappers ───────────────────────────────────────────────────────────────────

function dtoToView(dto: SavedViewDto): SavedView {
  return {
    id: String(dto.id),
    name: dto.name,
    type: dto.is_shared ? 'team' : 'personal',
    isDefault: dto.is_default,
    ownerId: dto.user_id,
    state: {
      visibleFields: dto.payload.columns ?? [],
      sort: dto.payload.sort ?? null,
      density: (dto.payload.density as ContactsDensity) ?? 'normal',
      filters: (dto.payload.filters as unknown as ContactsOverlayFilters) ?? ({} as ContactsOverlayFilters),
      search: '',
    },
  }
}

function stateToPayload(state: SavedViewState): SavedViewPayload {
  return {
    columns: state.visibleFields,
    // Omit sort key entirely when null — backend 'sometimes','array' rejects null
    ...(state.sort ? { sort: state.sort } : {}),
    density: state.density,
    filters: state.filters as unknown as Record<string, unknown>,
  }
}

// ── localStorage cache helpers ────────────────────────────────────────────────

function cacheKey(entityType: EntityType): string {
  return `mgcrm_saved_views_cache_v2_${entityType}`
}

function loadCache(entityType: EntityType): SavedView[] {
  try {
    const raw = localStorage.getItem(cacheKey(entityType))
    if (!raw) return []
    return JSON.parse(raw) as SavedView[]
  } catch {
    return []
  }
}

function saveCache(entityType: EntityType, views: SavedView[]): void {
  try {
    localStorage.setItem(cacheKey(entityType), JSON.stringify(views))
  } catch {
    // quota exceeded — ignore
  }
}

// ── Composable ────────────────────────────────────────────────────────────────

export function useSavedViews(opts: { entityType: { value: EntityType } }) {
  const { t } = useI18n()
  const toast = useToast()

  // Server-fetched views (starts from cache for instant render)
  const views = ref<SavedView[]>(loadCache(opts.entityType.value))

  // Track which entity type is currently loaded to avoid stale cache on tab switch
  const loadedEntityType = ref<EntityType | null>(null)

  // We type the resource as SavedViewDto[] — mapping to SavedView[] happens in commit
  const resource = useAsyncResource<SavedViewDto[]>([])
  const createMutation = useMutation<SavedViewDto>()
  const updateMutation = useMutation<SavedViewDto>()
  const deleteMutation = useMutation<void>()
  const defaultMutation = useMutation<SavedViewDto>()

  const isLoading = computed(() => resource.loading.value)
  const loadError = computed(() => resource.error.value)

  // Default view id from server
  const defaultViewId = computed<string | null>(() => {
    const found = views.value.find((v) => v.isDefault)
    return found?.id ?? null
  })

  // ── Load ────────────────────────────────────────────────────────────────────

  async function load(entityType?: EntityType) {
    const type = entityType ?? opts.entityType.value

    // Seed from cache so the UI is instant
    if (loadedEntityType.value !== type) {
      views.value = loadCache(type)
    }

    await resource.run(() => savedViewsApi.list(type), {
      commit(result) {
        const mapped = result.map(dtoToView)
        views.value = mapped
        loadedEntityType.value = type
        saveCache(type, mapped)
      },
    })
  }

  // Reload when entity type changes
  watch(
    () => opts.entityType.value,
    (type) => {
      void load(type)
    },
  )

  // ── Create ──────────────────────────────────────────────────────────────────

  async function addView(
    name: string,
    type: 'personal' | 'team',
    state: SavedViewState,
    makeDefault = false,
  ): Promise<SavedView | null> {
    let created: SavedView | null = null
    await createMutation.run(
      () =>
        savedViewsApi.create({
          name,
          entity_type: opts.entityType.value,
          is_shared: type === 'team',
          is_default: makeDefault,
          payload: stateToPayload(state),
        }),
      {
        onSuccess(dto) {
          const view = dtoToView(dto)
          // If set as default, clear previous defaults
          if (dto.is_default) {
            views.value = views.value.map((v) => ({ ...v, isDefault: false }))
          }
          views.value = [...views.value, view]
          saveCache(opts.entityType.value, views.value)
          created = view
          toast.add({
            severity: 'success',
            summary: t('crm.saved_views.toast.created'),
            life: 3000,
          })
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('crm.saved_views.toast.createError'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      },
    )
    return created
  }

  // ── Update ──────────────────────────────────────────────────────────────────

  async function updateView(
    id: string,
    patch: { name?: string; isShared?: boolean; state?: SavedViewState },
  ): Promise<void> {
    const numericId = parseInt(id, 10)
    if (isNaN(numericId)) return

    await updateMutation.run(
      () =>
        savedViewsApi.update(numericId, {
          name: patch.name,
          is_shared: patch.isShared,
          payload: patch.state ? stateToPayload(patch.state) : undefined,
        }),
      {
        onSuccess(dto) {
          const updated = dtoToView(dto)
          views.value = views.value.map((v) => (v.id === id ? updated : v))
          saveCache(opts.entityType.value, views.value)
          toast.add({
            severity: 'success',
            summary: t('crm.saved_views.toast.updated'),
            life: 3000,
          })
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('crm.saved_views.toast.updateError'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      },
    )
  }

  // ── Delete ──────────────────────────────────────────────────────────────────

  async function removeView(id: string): Promise<void> {
    const numericId = parseInt(id, 10)
    if (isNaN(numericId)) return

    await deleteMutation.run(() => savedViewsApi.remove(numericId), {
      onSuccess() {
        views.value = views.value.filter((v) => v.id !== id)
        saveCache(opts.entityType.value, views.value)
        toast.add({
          severity: 'success',
          summary: t('crm.saved_views.toast.deleted'),
          life: 3000,
        })
      },
      onError(err) {
        toast.add({
          severity: 'error',
          summary: t('crm.saved_views.toast.deleteError'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      },
    })
  }

  // ── Set default ─────────────────────────────────────────────────────────────

  async function setDefault(id: string): Promise<void> {
    const numericId = parseInt(id, 10)
    if (isNaN(numericId)) return

    await defaultMutation.run(() => savedViewsApi.setDefault(numericId), {
      onSuccess(dto) {
        // Clear all defaults for entity type, then set the new one
        views.value = views.value.map((v) => ({
          ...v,
          isDefault: v.id === String(dto.id),
        }))
        saveCache(opts.entityType.value, views.value)
        toast.add({
          severity: 'success',
          summary: t('crm.saved_views.toast.defaultSet'),
          life: 3000,
        })
      },
      onError(err) {
        toast.add({
          severity: 'error',
          summary: t('crm.saved_views.toast.defaultError'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      },
    })
  }

  // ── Read state ──────────────────────────────────────────────────────────────

  function getViewState(id: string): SavedViewState | null {
    const view = views.value.find((v) => v.id === id)
    return view?.state ?? null
  }

  return {
    views,
    isLoading,
    loadError,
    defaultViewId,
    load,
    addView,
    updateView,
    removeView,
    setDefault,
    getViewState,
  }
}
