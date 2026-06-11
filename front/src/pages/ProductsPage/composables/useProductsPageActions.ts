import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { catalogApi } from '@/api/catalog'
import { getApiErrorStatus, getApiErrorMessage } from '@/utils/errors'
import type { ProductDto } from '@/entities/catalog'

interface UseProductsPageActionsOptions {
  reload: () => Promise<void>
}

export const useProductsPageActions = ({ reload }: UseProductsPageActionsOptions) => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const createDrawerOpen = ref(false)
  const editingProduct = ref<ProductDto | null>(null)
  const importDialogOpen = ref(false)

  const toggleMutation = useMutation<ProductDto>()
  const deleteMutation = useMutation<void>()

  function openCreateDrawer() {
    editingProduct.value = null
    createDrawerOpen.value = true
  }

  function openEditDrawer(product: ProductDto) {
    editingProduct.value = product
    createDrawerOpen.value = true
  }

  function closeDrawer() {
    createDrawerOpen.value = false
    editingProduct.value = null
  }

  function openImportDialog() {
    importDialogOpen.value = true
  }

  function closeImportDialog() {
    importDialogOpen.value = false
  }

  async function toggleActive(product: ProductDto) {
    const newValue = !product.is_active
    // Optimistic
    product.is_active = newValue
    try {
      await toggleMutation.run(() =>
        catalogApi.updateProduct(product.id, { is_active: newValue }),
      )
      toast.add({
        severity: 'success',
        summary: t('catalog.products.page.actions.toggleSuccess'),
        life: 2000,
      })
    } catch (err) {
      // Rollback
      product.is_active = !newValue
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  function confirmDelete(product: ProductDto) {
    confirm.require({
      message: t('catalog.products.page.actions.deleteDetail'),
      header: t('catalog.products.page.actions.deleteConfirm'),
      icon: 'pi pi-trash',
      acceptLabel: t('catalog.products.page.actions.deleteAccept'),
      rejectLabel: t('catalog.products.page.actions.deleteReject'),
      acceptClass: 'p-button-danger',
      accept: () => void deleteProduct(product),
    })
  }

  async function deleteProduct(product: ProductDto) {
    try {
      await deleteMutation.run(() => catalogApi.deleteProduct(product.id))
      toast.add({
        severity: 'success',
        summary: t('catalog.products.page.actions.deleteSuccess'),
        life: 3000,
      })
      await reload()
    } catch (err) {
      const status = getApiErrorStatus(err)
      if (status === 409) {
        toast.add({
          severity: 'warn',
          summary: t('catalog.products.page.actions.deleteUsed'),
          life: 5000,
        })
      } else {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    }
  }

  async function downloadTemplate() {
    const url = catalogApi.downloadTemplateUrl()
    const link = document.createElement('a')
    link.href = url
    link.download = 'price_template.xlsx'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  return {
    createDrawerOpen,
    editingProduct,
    importDialogOpen,
    toggleMutation,
    deleteMutation,
    openCreateDrawer,
    openEditDrawer,
    closeDrawer,
    openImportDialog,
    closeImportDialog,
    toggleActive,
    confirmDelete,
    downloadTemplate,
  }
}
