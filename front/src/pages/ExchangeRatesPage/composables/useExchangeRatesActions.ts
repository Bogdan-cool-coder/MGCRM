import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { catalogApi } from '@/api/catalog'
import { getApiErrorMessage } from '@/utils/errors'
import type { ExchangeRateDto } from '@/entities/catalog'

interface UseExchangeRatesActionsOptions {
  reload: () => Promise<void>
}

export const useExchangeRatesActions = ({ reload }: UseExchangeRatesActionsOptions) => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const manualDialogOpen = ref(false)
  const editingRate = ref<ExchangeRateDto | null>(null)

  const refreshMutation = useMutation<void>()
  const deleteMutation = useMutation<void>()
  const saveMutation = useMutation<ExchangeRateDto>()

  const refreshing = refreshMutation.isPending

  function openCreateDialog() {
    editingRate.value = null
    manualDialogOpen.value = true
  }

  function openEditDialog(rate: ExchangeRateDto) {
    editingRate.value = rate
    manualDialogOpen.value = true
  }

  function closeDialog() {
    manualDialogOpen.value = false
    editingRate.value = null
  }

  async function refreshRates() {
    try {
      await refreshMutation.run(() => catalogApi.refreshRates())
      toast.add({
        severity: 'success',
        summary: t('catalog.exchangeRates.page.actions.refreshSuccess'),
        life: 3000,
      })
      await reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('catalog.exchangeRates.page.actions.refreshError'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  async function saveRate(payload: {
    from_code: string
    to_code: string
    rate: number
    date: string
    id?: number
  }) {
    try {
      if (payload.id) {
        await saveMutation.run(() =>
          catalogApi.updateExchangeRate(payload.id!, {
            from_code: payload.from_code,
            to_code: payload.to_code,
            rate: payload.rate,
            date: payload.date,
          }),
        )
      } else {
        await saveMutation.run(() =>
          catalogApi.createExchangeRate({
            from_code: payload.from_code,
            to_code: payload.to_code,
            rate: payload.rate,
            date: payload.date,
          }),
        )
      }
      toast.add({
        severity: 'success',
        summary: t('catalog.exchangeRates.manual.success'),
        life: 2000,
      })
      closeDialog()
      await reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
      throw err
    }
  }

  function confirmDelete(rate: ExchangeRateDto) {
    confirm.require({
      message: t('catalog.exchangeRates.page.actions.deleteConfirm'),
      header: t('catalog.exchangeRates.page.actions.deleteConfirm'),
      icon: 'pi pi-trash',
      acceptLabel: t('catalog.products.page.actions.deleteAccept'),
      rejectLabel: t('catalog.products.page.actions.deleteReject'),
      acceptClass: 'p-button-danger',
      accept: () => void deleteRate(rate),
    })
  }

  async function deleteRate(rate: ExchangeRateDto) {
    try {
      await deleteMutation.run(() => catalogApi.deleteExchangeRate(rate.id))
      toast.add({
        severity: 'success',
        summary: t('catalog.exchangeRates.page.actions.deleteSuccess'),
        life: 2000,
      })
      await reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  return {
    manualDialogOpen,
    editingRate,
    refreshing,
    saveMutation,
    openCreateDialog,
    openEditDialog,
    closeDialog,
    refreshRates,
    saveRate,
    confirmDelete,
  }
}
