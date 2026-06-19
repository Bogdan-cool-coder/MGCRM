/**
 * Bulk selection + operations for ContactsPage.
 * Supports contacts and companies via separate API endpoints.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
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
  const confirm = useConfirm()

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

  // ── Bulk delete ─────────────────────────────────────────────────────────────
  function confirmBulkDelete() {
    confirm.require({
      message: t('contacts.page.delete.detail'),
      header: t('contacts.page.delete.confirm'),
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: t('contacts.page.delete.accept'),
      rejectLabel: t('contacts.page.delete.reject'),
      acceptClass: 'p-button-danger',
      accept: async () => {
        try {
          if (opts.entityType.value === 'contact') {
            await contactsApi.bulkDelete(selectedIdsList.value)
          } else {
            await companiesApi.bulkDelete(selectedIdsList.value)
          }
          toast.add({
            severity: 'success',
            summary: t('contacts.page.delete.success'),
            life: 4000,
          })
          clearSelection()
          await opts.reload()
        } catch (err) {
          toast.add({
            severity: 'error',
            summary: t('contacts.page.errors.delete'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        }
      },
    })
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
  }
}
