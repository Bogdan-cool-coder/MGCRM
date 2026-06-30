/**
 * Bulk selection + operations for ContactsPage.
 * Supports contacts and companies via separate API endpoints.
 *
 * NOTE: delete confirmation uses a custom reactive Dialog (not useConfirm/ConfirmDialog)
 * to avoid the PrimeVue ConfirmService phantom-on-route-leave bug.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { contactsApi } from '@/api/crm/contacts'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { EntityType } from './useContactsPageData'

export function useContactsBulk(opts: {
  entityType: { value: EntityType }
  allIds: { value: number[] }
  reload: () => Promise<void>
}) {
  const { t } = useI18n()
  const toast = useToast()

  const bulkMode = ref(false)
  const selectedIds = ref<Set<number>>(new Set())

  const selectedCount = computed(() => selectedIds.value.size)
  const selectedIdsList = computed(() => Array.from(selectedIds.value))

  const allSelected = computed(
    () =>
      opts.allIds.value.length > 0 &&
      opts.allIds.value.every((id) => selectedIds.value.has(id)),
  )

  function enterBulk() {
    bulkMode.value = true
    selectedIds.value = new Set()
  }

  function exitBulk() {
    bulkMode.value = false
    selectedIds.value = new Set()
  }

  function toggleItem(id: number) {
    if (selectedIds.value.has(id)) {
      selectedIds.value.delete(id)
    } else {
      selectedIds.value.add(id)
    }
    // Force reactivity
    selectedIds.value = new Set(selectedIds.value)
  }

  function selectAll() {
    selectedIds.value = new Set(opts.allIds.value)
  }

  function clearSelection() {
    selectedIds.value = new Set()
  }

  // ── Exporting ────────────────────────────────────────────────────────────────
  const exporting = ref(false)

  async function exportXlsx() {
    exporting.value = true
    try {
      let blob: Blob
      if (opts.entityType.value === 'contact') {
        blob = await contactsApi.exportXlsx(selectedIdsList.value)
      } else {
        blob = await companiesApi.exportXlsx(selectedIdsList.value)
      }
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${opts.entityType.value === 'contact' ? 'contacts' : 'companies'}_${Date.now()}.xlsx`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      toast.add({ severity: 'success', summary: t('common.success'), life: 3000 })
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.load'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      exporting.value = false
    }
  }

  // ── Bulk assign owner ────────────────────────────────────────────────────────
  const assignOwnerOpen = ref(false)
  const assignOwnerLoading = ref(false)

  function openAssignOwner() {
    assignOwnerOpen.value = true
  }

  async function submitAssignOwner(userId: number) {
    assignOwnerLoading.value = true
    try {
      if (opts.entityType.value === 'contact') {
        await contactsApi.bulkPatch({
          contact_ids: selectedIdsList.value,
          operation: 'assign_owner',
          owner_id: userId,
        })
      } else {
        await companiesApi.bulkPatch({
          company_ids: selectedIdsList.value,
          operation: 'assign_responsible',
          responsible_user_id: userId,
        })
      }
      toast.add({ severity: 'success', summary: t('common.success'), life: 3000 })
      assignOwnerOpen.value = false
      await opts.reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.create'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      assignOwnerLoading.value = false
    }
  }

  // ── Bulk add tag ─────────────────────────────────────────────────────────────
  const addTagOpen = ref(false)
  const addTagLoading = ref(false)

  function openAddTag() {
    addTagOpen.value = true
  }

  async function submitAddTag(tag: string) {
    addTagLoading.value = true
    try {
      if (opts.entityType.value === 'contact') {
        await contactsApi.bulkPatch({
          contact_ids: selectedIdsList.value,
          operation: 'add_tag',
          tag,
        })
      } else {
        await companiesApi.bulkPatch({
          company_ids: selectedIdsList.value,
          operation: 'add_tag',
          tag,
        })
      }
      toast.add({ severity: 'success', summary: t('common.success'), life: 3000 })
      addTagOpen.value = false
      await opts.reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.create'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      addTagLoading.value = false
    }
  }

  // ── Bulk delete — custom dialog (NOT useConfirm/ConfirmDialog) ───────────────
  // Reason: PrimeVue ConfirmService leaves a phantom dialog open on route-leave.
  const bulkDeleteOpen = ref(false)
  const bulkDeleteLoading = ref(false)

  function confirmBulkDelete() {
    bulkDeleteOpen.value = true
  }

  async function executeBulkDelete() {
    bulkDeleteLoading.value = true
    try {
      if (opts.entityType.value === 'contact') {
        await contactsApi.bulkDelete(selectedIdsList.value)
      } else {
        await companiesApi.bulkDelete(selectedIdsList.value)
      }
      // Close immediately after DELETE resolves — before reload — so the dialog
      // disappears instantly and the user does not watch a 3-second spinner while
      // the list re-fetches.
      bulkDeleteOpen.value = false
      bulkDeleteLoading.value = false
      toast.add({
        severity: 'success',
        summary: t('contacts.page.delete.success'),
        life: 4000,
      })
      clearSelection()
      exitBulk()
      // Fire reload in the background — the dialog is already gone.
      void opts.reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.delete'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      // Failsafe: ensure dialog/loading reset even if error threw before our
      // early-close block above (e.g. network error before the close lines).
      bulkDeleteOpen.value = false
      bulkDeleteLoading.value = false
    }
  }

  return {
    bulkMode,
    selectedIds,
    selectedCount,
    selectedIdsList,
    allSelected,
    exporting,
    assignOwnerOpen,
    assignOwnerLoading,
    addTagOpen,
    addTagLoading,
    bulkDeleteOpen,
    bulkDeleteLoading,
    enterBulk,
    exitBulk,
    toggleItem,
    selectAll,
    clearSelection,
    exportXlsx,
    openAssignOwner,
    submitAssignOwner,
    openAddTag,
    submitAddTag,
    confirmBulkDelete,
    executeBulkDelete,
  }
}
