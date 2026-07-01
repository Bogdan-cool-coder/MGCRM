import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { contactsApi } from '@/api/crm/contacts'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { EntityType } from './useContactsPageData'

export const useContactsPageActions = (opts: {
  reload: () => Promise<void>
  entityType: { value: EntityType }
}) => {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()

  // Dedup dialog
  const dedupOpen = ref(false)

  // ── Single-item delete dialog (NOT useConfirm — phantom-on-route-leave bug) ──
  const deleteOpen = ref(false)
  const deleteLoading = ref(false)
  const deleteTarget = ref<{ id: number; type: EntityType; label: string } | null>(null)

  const deleteHeader = ref('')
  const deleteMessage = ref('')

  function confirmDelete(item: { id: number; name?: string; full_name?: string }, type: EntityType) {
    const name = item.full_name ?? item.name ?? ''
    deleteTarget.value = { id: item.id, type, label: name }
    deleteHeader.value = t('contacts.page.delete.confirm') + (name ? ` "${name}"` : '')
    deleteMessage.value = t('contacts.page.delete.detail')
    deleteOpen.value = true
  }

  async function executeDelete() {
    if (!deleteTarget.value) return
    deleteLoading.value = true
    try {
      if (deleteTarget.value.type === 'company') {
        await companiesApi.remove(deleteTarget.value.id)
      } else {
        await contactsApi.remove(deleteTarget.value.id)
      }
      toast.add({
        severity: 'success',
        summary: t('contacts.page.delete.success'),
        life: 4000,
      })
      deleteTarget.value = null
      void opts.reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.delete'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      // Always close the dialog and reset loading — regardless of success or error.
      // Closing in finally (not try) guarantees the dialog dismisses even when the
      // API call throws. Loading is reset AFTER the close flag so PrimeVue Dialog
      // starts its leave-transition without a spinner-flash on the next frame.
      deleteOpen.value = false
      deleteLoading.value = false
    }
  }

  function openDedup() {
    dedupOpen.value = true
  }

  function openCard(item: { id: number }, type: EntityType) {
    if (type === 'company') {
      void router.push(`/companies/${item.id}`)
    } else {
      void router.push(`/contacts/${item.id}`)
    }
  }

  return {
    dedupOpen,
    // single-item delete dialog
    deleteOpen,
    deleteLoading,
    deleteHeader,
    deleteMessage,
    openDedup,
    openCard,
    confirmDelete,
    executeDelete,
  }
}
