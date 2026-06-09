import { computed, ref, watch, type Ref } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { getApiErrorStatus } from '@/utils/errors'
import { getLocalizedText } from '@/utils/localization'
import { isKnownPlaceholder } from '@/entities/document'
import type {
  DocumentFieldCatalog,
  DocumentFieldMapping,
  DocumentTemplate,
} from '@/entities/document'

type Translate = (key: string) => string

interface UseDocumentDocxArgs {
  t: Translate
  locale: Ref<string>
  documentId: Ref<number>
  template: Ref<DocumentTemplate | null>
  /** Only the docx flow loads source / placeholders. */
  isDocx: Ref<boolean>
  /** Gate upload (analyst+ / non-viewer, non-system). */
  canManage: Ref<boolean>
}

/**
 * One placeholder row rendered read-only in the docx panel. Since v2 the
 * placeholders ARE the canonical catalog keys (`${estate.price}`), so there is
 * no manual mapping step — the panel just lists the extracted tokens and flags
 * each as known (resolves against the field catalogue) or unknown.
 */
export interface PlaceholderRow {
  /** Bare `${token}` name from the docx source (may carry a `|filter` suffix). */
  token: string
  /** True when the token (sans filter) resolves to a catalogue key. */
  known: boolean
}

/**
 * Docx-specific data layer for `/documents/:id` (Word template flow, v2):
 *   - lazy-loads the static field catalogue (reference modal + known-check),
 *   - loads the `${...}` placeholders of the uploaded source and flags each
 *     as known / unknown against the catalogue,
 *   - uploads / replaces the `.docx` source (then refetches placeholders),
 *   - exposes `applyMappings` + `saveMapping` for the AI auto-map flow only
 *     (persists `config.field_mapping`; users no longer map by hand).
 *
 * The object / promotion / discount selectors are shared with the HTML flow and
 * live in `useDocumentPageData`; this composable only owns the docx extras.
 */
export const useDocumentDocx = (args: UseDocumentDocxArgs) => {
  const { t, locale, documentId, template, isDocx, canManage } = args
  const { documentService } = useServices()
  const { notifyApiError, notifySuccess } = useNotifications()

  // ─── Field catalogue (lazy, app-wide static reference) ─────────────────────
  const fieldCatalog = ref<DocumentFieldCatalog | null>(null)
  const catalogLoading = ref(false)

  const loadFieldCatalog = async () => {
    if (fieldCatalog.value !== null || catalogLoading.value) return
    catalogLoading.value = true
    try {
      fieldCatalog.value = await documentService.fetchFieldCatalog()
    } catch (error: unknown) {
      notifyApiError(error, t('docx.errors.loadCatalog'))
    } finally {
      catalogLoading.value = false
    }
  }

  /** Resolve a catalog key → localized label (used by the AI proposal cards). */
  const labelForKey = (key: string): string => {
    const catalog = fieldCatalog.value
    if (catalog === null) return key
    const bare = key.split('|', 1)[0]?.trim() ?? key
    for (const group of Object.values(catalog)) {
      const entry = group.find((e) => e.key === bare)
      if (entry) return getLocalizedText(entry.label, locale.value)
    }
    return key
  }

  // ─── Placeholders (read-only, known/unknown) ───────────────────────────────
  const placeholders = ref<string[]>([])
  const placeholdersLoading = ref(false)
  /** True once a source has been uploaded. */
  const hasSource = computed(() => (template.value?.sourcePath ?? null) !== null)

  const loadPlaceholders = async () => {
    const id = documentId.value
    if (!id || id <= 0 || !isDocx.value) return
    // No source uploaded yet → nothing to fetch (422 from the endpoint).
    if (!hasSource.value) {
      placeholders.value = []
      return
    }
    placeholdersLoading.value = true
    try {
      placeholders.value = await documentService.fetchPlaceholders(id)
    } catch (error: unknown) {
      // 422 = source vanished / not a docx; treat as "no placeholders" silently.
      if (getApiErrorStatus(error) === 422) {
        placeholders.value = []
      } else {
        notifyApiError(error, t('docx.errors.loadPlaceholders'))
      }
    } finally {
      placeholdersLoading.value = false
    }
  }

  /** Rows for the read-only placeholder list, each flagged known / unknown. */
  const placeholderRows = computed<PlaceholderRow[]>(() => {
    const catalog = fieldCatalog.value
    return placeholders.value.map((token) => ({
      token,
      known: catalog !== null && isKnownPlaceholder(token, catalog),
    }))
  })

  /** Tokens that don't resolve to any catalogue key (shown as a warning). */
  const unknownTokens = computed<string[]>(() =>
    placeholderRows.value.filter((r) => !r.known).map((r) => r.token),
  )

  const hasUnknown = computed(() => unknownTokens.value.length > 0)

  // ─── AI auto-mapping persist path (no manual mapping UI) ───────────────────
  // The AI proposal flow merges suggestions into a transient draft and persists
  // them into `config.field_mapping` as an optional fallback. Users don't edit
  // this by hand any more — placeholders ARE the canonical keys.
  const mappingDraft = ref<DocumentFieldMapping>({})
  const savingMapping = ref(false)

  const seedMappingFromTemplate = () => {
    const saved = (template.value?.config.field_mapping ?? {}) as DocumentFieldMapping
    mappingDraft.value = { ...saved }
  }

  /**
   * Bulk-merge AI-proposed mappings into the draft (token → field). Only tokens
   * present in the loaded placeholder list are applied (defensive against a
   * stale proposal). The caller persists with `saveMapping()` afterwards.
   */
  const applyMappings = (entries: Record<string, string>) => {
    const next = { ...mappingDraft.value }
    const known = new Set(placeholders.value)
    for (const [token, field] of Object.entries(entries)) {
      if (!known.has(token)) continue
      if (typeof field !== 'string' || field === '') continue
      next[token] = field
    }
    mappingDraft.value = next
  }

  /** Count of placeholders recognised by the catalogue (for mini-chat context). */
  const mappedCount = computed(() => placeholderRows.value.filter((r) => r.known).length)

  const saveMapping = async () => {
    const id = documentId.value
    if (!id || id <= 0 || !canManage.value || savingMapping.value) return
    savingMapping.value = true
    try {
      const existingConfig = template.value?.config ?? {}
      const updated = await documentService.updateTemplate(id, {
        config: { ...existingConfig, field_mapping: { ...mappingDraft.value } },
      })
      // Reflect the persisted config so a re-seed stays in sync.
      if (template.value !== null) template.value = updated
      notifySuccess(t('docx.mapping.saved'))
    } catch (error: unknown) {
      notifyApiError(error, t('docx.errors.saveMapping'))
    } finally {
      savingMapping.value = false
    }
  }

  // ─── Source upload / replace ───────────────────────────────────────────────
  const uploading = ref(false)

  const uploadSource = async (file: File) => {
    const id = documentId.value
    if (!id || id <= 0 || !canManage.value || uploading.value) return
    uploading.value = true
    try {
      const sourcePath = await documentService.uploadSourceFile(id, file)
      // Patch the template in place so `hasSource` flips. The watcher on
      // [documentId, isDocx, hasSource] refetches placeholders on that change —
      // when replacing an already-uploaded source (hasSource stays true), fetch
      // explicitly since the watcher won't re-fire.
      const wasSourced = hasSource.value
      if (template.value !== null) {
        template.value = { ...template.value, sourcePath }
      }
      notifySuccess(t('docx.upload.uploaded'))
      if (wasSourced) await loadPlaceholders()
    } catch (error: unknown) {
      notifyApiError(error, t('docx.errors.upload'))
    } finally {
      uploading.value = false
    }
  }

  // ─── Reference modal ───────────────────────────────────────────────────────
  const catalogModalVisible = ref(false)

  const openCatalogModal = () => {
    void loadFieldCatalog()
    catalogModalVisible.value = true
  }

  const closeCatalogModal = () => {
    catalogModalVisible.value = false
  }

  // ─── Reactive load: when the docx template (or its source) resolves ────────
  watch(
    [documentId, isDocx, hasSource],
    () => {
      if (!isDocx.value) {
        placeholders.value = []
        mappingDraft.value = {}
        return
      }
      void loadFieldCatalog()
      seedMappingFromTemplate()
      void loadPlaceholders()
    },
    { immediate: true },
  )

  return {
    // catalogue
    fieldCatalog,
    catalogLoading,
    labelForKey,
    // placeholders
    hasSource,
    placeholders,
    placeholdersLoading,
    placeholderRows,
    unknownTokens,
    hasUnknown,
    mappedCount,
    // AI auto-mapping persist path
    savingMapping,
    applyMappings,
    saveMapping,
    // upload
    uploading,
    uploadSource,
    // reference modal
    catalogModalVisible,
    openCatalogModal,
    closeCatalogModal,
  }
}
