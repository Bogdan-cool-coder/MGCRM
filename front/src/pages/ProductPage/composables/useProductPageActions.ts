import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { catalogApi } from '@/api/catalog'
import { getApiErrorStatus, getApiErrorMessage } from '@/utils/errors'
import { toKopecks } from '@/utils/currency'
import type { ProductDto, ProductPlanDto, ProductPriceDto } from '@/entities/catalog'

interface UseProductPageActionsOptions {
  product: import('vue').Ref<ProductDto | null>
  reload: () => Promise<void>
}

export const useProductPageActions = ({ product, reload }: UseProductPageActionsOptions) => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const editDrawerOpen = ref(false)
  const planDialogOpen = ref(false)
  const editingPlan = ref<ProductPlanDto | null>(null)

  const patchMutation = useMutation<ProductDto>()
  const planMutation = useMutation<ProductPlanDto>()
  const deletePlanMutation = useMutation<void>()
  const priceMutation = useMutation<ProductPriceDto[]>()

  function openEditDrawer() {
    editDrawerOpen.value = true
  }

  function openCreatePlanDialog() {
    editingPlan.value = null
    planDialogOpen.value = true
  }

  function openEditPlanDialog(plan: ProductPlanDto) {
    editingPlan.value = plan
    planDialogOpen.value = true
  }

  async function toggleActive() {
    if (!product.value) return
    const newValue = !product.value.is_active
    product.value.is_active = newValue
    try {
      const updated = await patchMutation.run(() =>
        catalogApi.updateProduct(product.value!.id, { is_active: newValue }),
      )
      product.value = updated
      toast.add({
        severity: 'success',
        summary: t('catalog.products.page.actions.toggleSuccess'),
        life: 2000,
      })
    } catch (err) {
      if (product.value) product.value.is_active = !newValue
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  async function savePrice(
    planId: number | null,
    currencyCode: string,
    valueInUnits: number,
  ) {
    if (!product.value) return
    const amount = toKopecks(valueInUnits)
    try {
      await priceMutation.run(() =>
        catalogApi.upsertPrices(product.value!.id, {
          prices: [{ plan_id: planId, currency_code: currencyCode, amount }],
        }),
      )
      toast.add({
        severity: 'success',
        summary: t('catalog.product.page.prices.saved'),
        life: 2000,
      })
      await reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('catalog.product.page.prices.saveError'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
      throw err
    }
  }

  async function createPlan(payload: {
    name: string
    code?: string | null
    unit: string
    sort_order?: number
    is_active?: boolean
  }) {
    if (!product.value) return
    try {
      await planMutation.run(() =>
        catalogApi.createProductPlan(product.value!.id, payload),
      )
      toast.add({
        severity: 'success',
        summary: t('catalog.product.page.plan.save'),
        life: 2000,
      })
      await reload()
      planDialogOpen.value = false
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

  async function updatePlan(
    planId: number,
    payload: {
      name?: string
      code?: string | null
      unit?: string
      sort_order?: number
      is_active?: boolean
    },
  ) {
    if (!product.value) return
    try {
      await planMutation.run(() =>
        catalogApi.updateProductPlan(product.value!.id, planId, payload),
      )
      toast.add({
        severity: 'success',
        summary: t('catalog.product.page.plan.save'),
        life: 2000,
      })
      await reload()
      planDialogOpen.value = false
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

  function confirmDeletePlan(plan: ProductPlanDto) {
    confirm.require({
      message: t('catalog.product.page.plan.deleteConfirm'),
      header: t('catalog.product.page.plan.deleteConfirm'),
      icon: 'pi pi-trash',
      acceptLabel: t('catalog.products.page.actions.deleteAccept'),
      rejectLabel: t('catalog.products.page.actions.deleteReject'),
      acceptClass: 'p-button-danger',
      accept: () => void deletePlan(plan),
    })
  }

  async function deletePlan(plan: ProductPlanDto) {
    if (!product.value) return
    try {
      await deletePlanMutation.run(() =>
        catalogApi.deleteProductPlan(product.value!.id, plan.id),
      )
      toast.add({
        severity: 'success',
        summary: t('catalog.product.page.plan.deleteSuccess'),
        life: 2000,
      })
      await reload()
    } catch (err) {
      const status = getApiErrorStatus(err)
      if (status === 409) {
        toast.add({
          severity: 'warn',
          summary: t('catalog.product.page.plan.deleteUsed'),
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

  return {
    editDrawerOpen,
    planDialogOpen,
    editingPlan,
    patchMutation,
    planMutation,
    priceMutation,
    openEditDrawer,
    openCreatePlanDialog,
    openEditPlanDialog,
    toggleActive,
    savePrice,
    createPlan,
    updatePlan,
    confirmDeletePlan,
  }
}
