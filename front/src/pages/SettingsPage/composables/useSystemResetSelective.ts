import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useRouter } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import {
  systemApi,
  SELECTIVE_RESET_CONFIRMATION,
  type ResetPreviewCategory,
  type SelectiveResetResponse,
} from '@/api/system'
import { useUserStore } from '@/stores/user'
import { getApiErrorMessage, getApiErrorStatus } from '@/utils/errors'

/**
 * State machine for the redesigned selective System-reset UI.
 *
 * Backend contract: docs/contracts/system-reset-api-contract.md.
 * - Categories + counts + `requires` come from GET /api/system/reset/preview.
 * - The prerequisite matrix (`requires`) is authoritative from the API — never
 *   hardcoded here (§8.2). Selecting a category auto-selects its prerequisites.
 * - Backend endpoints may not be live yet: 404/501 → graceful "unavailable" state
 *   (empty card, no phantom errors); the section still renders the danger banner.
 */
export function useSystemResetSelective() {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()
  const userStore = useUserStore()

  // ─── Preview (counts + requires matrix + phrase) ─────────────────────────────
  const preview = useAsyncResource<ResetPreviewCategory[]>(() => [])
  const previewEnabled = ref(true)
  const confirmationPhrase = ref(SELECTIVE_RESET_CONFIRMATION)

  /** True when preview endpoint is not yet deployed (404/501) — feature unavailable. */
  const unavailable = ref(false)

  async function loadPreview(): Promise<void> {
    unavailable.value = false
    try {
      await preview.run(async () => {
        const res = await systemApi.resetPreview()
        previewEnabled.value = res.enabled
        confirmationPhrase.value = res.confirmation_phrase || SELECTIVE_RESET_CONFIRMATION
        return res.categories
      })
    } catch (error) {
      const status = getApiErrorStatus(error)
      // 404 (route not deployed) / 501 (not implemented) → treat as unavailable.
      // 403 (reset disabled for env) → also unavailable, but keep a clear message.
      if (status === 404 || status === 501) {
        unavailable.value = true
      } else if (status === 403) {
        previewEnabled.value = false
        preview.reset([])
      }
      // Any other error stays in preview.error for the template's error state.
    }
  }

  const categories = computed<ResetPreviewCategory[]>(() => preview.data.value)

  /** key → requires[] lookup, straight from the API. */
  const requiresMap = computed<Record<string, string[]>>(() => {
    const map: Record<string, string[]> = {}
    for (const cat of categories.value) map[cat.key] = cat.requires
    return map
  })

  // ─── Selection ───────────────────────────────────────────────────────────────
  const selected = ref<Set<string>>(new Set())

  const selectedKeys = computed<string[]>(() =>
    categories.value.filter((c) => selected.value.has(c.key)).map((c) => c.key),
  )
  const selectedCount = computed(() => selectedKeys.value.length)
  const totalCount = computed(() => categories.value.length)
  const allSelected = computed(
    () => totalCount.value > 0 && selectedCount.value === totalCount.value,
  )
  const someSelected = computed(
    () => selectedCount.value > 0 && !allSelected.value,
  )

  function isSelected(key: string): boolean {
    return selected.value.has(key)
  }

  /**
   * Toggle a category. Selecting pulls in its prerequisites (API `requires`);
   * deselecting removes categories that require this one (dependents), so the
   * selection never violates the prerequisite matrix and never 422s.
   */
  function toggle(key: string): void {
    const next = new Set(selected.value)
    if (next.has(key)) {
      next.delete(key)
      // Drop dependents that require the just-removed category.
      let changed = true
      while (changed) {
        changed = false
        for (const cat of categories.value) {
          if (next.has(cat.key) && cat.requires.some((r) => !next.has(r))) {
            next.delete(cat.key)
            changed = true
          }
        }
      }
    } else {
      next.add(key)
      // Pull in prerequisites transitively.
      let changed = true
      while (changed) {
        changed = false
        for (const k of [...next]) {
          for (const req of requiresMap.value[k] ?? []) {
            if (!next.has(req)) {
              next.add(req)
              changed = true
            }
          }
        }
      }
    }
    selected.value = next
  }

  function toggleAll(): void {
    if (allSelected.value) {
      selected.value = new Set()
    } else {
      selected.value = new Set(categories.value.map((c) => c.key))
    }
  }

  /** Names (labels) of the selected categories — used in the final modal body. */
  const selectedLabels = computed<string[]>(() =>
    categories.value.filter((c) => selected.value.has(c.key)).map((c) => c.label),
  )

  // ─── Confirmation phrase ─────────────────────────────────────────────────────
  const confirmWord = ref('')
  const phraseValid = computed(
    () => confirmWord.value.trim().toUpperCase() === confirmationPhrase.value.toUpperCase(),
  )
  /** True when the phrase was typed but is wrong AND a selection exists → red border. */
  const phraseInvalidShown = computed(
    () => !!confirmWord.value && !phraseValid.value && selectedCount.value > 0,
  )
  const canReset = computed(() => selectedCount.value > 0 && phraseValid.value)

  // ─── Final confirm modal + execute ───────────────────────────────────────────
  const modalVisible = ref(false)
  const resetMutation = useMutation<SelectiveResetResponse>()

  function openConfirmModal(): void {
    if (!canReset.value) return
    modalVisible.value = true
  }

  function closeConfirmModal(): void {
    modalVisible.value = false
  }

  async function execute(): Promise<void> {
    if (!canReset.value) return
    try {
      await resetMutation.run(
        async () => {
          const result = await systemApi.resetSelective({
            categories: selectedKeys.value,
            confirmation: confirmationPhrase.value,
          })

          if (result.failed.length > 0) {
            // Partial success — name the categories that failed (contract §8.5).
            const failedNames = result.failed
              .map((f) => t(`settings.system.reset.categories.${f.category}.name`, f.category))
              .join(', ')
            toast.add({
              severity: 'warn',
              summary: t('settings.system.reset.partialTitle'),
              detail: t('settings.system.reset.partialDetail', {
                ok: Object.keys(result.deleted).length,
                failed: result.failed.length,
                list: failedNames,
              }),
              life: 7000,
            })
          } else {
            toast.add({
              severity: 'success',
              summary: t('settings.system.reset.success'),
              detail: t('settings.system.reset.successDetail', {
                n: result.total_deleted,
              }),
              life: 5000,
            })
          }

          return result
        },
        {
          onSuccess: async (result) => {
            closeConfirmModal()
            selected.value = new Set()
            confirmWord.value = ''
            if (result.requires_relogin) {
              userStore.clearAuthenticatedUserState()
              await router.push({ path: '/login', query: { reason: 'reset' } })
              return
            }
            // Refresh counts (contract §8.4).
            await loadPreview()
          },
          onError: (error) => {
            const status = getApiErrorStatus(error)
            if (status === 404 || status === 501) {
              // Endpoint not deployed yet — soft "coming soon" instead of a hard error.
              closeConfirmModal()
              toast.add({
                severity: 'info',
                summary: t('settings.system.reset.unavailableTitle'),
                detail: t('settings.system.reset.unavailableToast'),
                life: 5000,
              })
              return
            }
            toast.add({
              severity: 'error',
              summary: t('settings.system.reset.errorTitle'),
              detail: getApiErrorMessage(error, t('errors.server_error')),
              life: 7000,
            })
          },
        },
      )
    } catch {
      // Handled in onError; swallow so the caller isn't forced to try/catch.
    }
  }

  return {
    // preview
    loadPreview,
    categories,
    previewLoading: preview.loading,
    previewError: preview.error,
    previewEnabled,
    unavailable,
    // selection
    isSelected,
    toggle,
    toggleAll,
    selectedKeys,
    selectedCount,
    selectedLabels,
    totalCount,
    allSelected,
    someSelected,
    // phrase
    confirmWord,
    confirmationPhrase,
    phraseValid,
    phraseInvalidShown,
    canReset,
    // execute
    modalVisible,
    openConfirmModal,
    closeConfirmModal,
    execute,
    isPending: resetMutation.isPending,
  }
}
