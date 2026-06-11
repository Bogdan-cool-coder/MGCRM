import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { catalogApi } from '@/api/catalog'
import { getApiErrorMessage } from '@/utils/errors'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import type { ProductDto } from '@/entities/catalog'

export const useProductPageData = (productId: number) => {
  const { t } = useI18n()
  const toast = useToast()

  const productResource = useAsyncResource<ProductDto | null>(() => null)

  const loading = productResource.loading
  const product = productResource.data
  const error = productResource.error

  async function load() {
    try {
      await productResource.run(() => catalogApi.getProduct(productId))
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('catalog.product.page.errors.load'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  return {
    loading,
    product,
    error,
    load,
  }
}
