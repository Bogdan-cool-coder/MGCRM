/**
 * Deal line-items composable — CRUD for deal products.
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import type { DealProductDto } from '@/entities/sales'

export function useDealProducts(dealId: () => number) {
  const resource = useAsyncResource<DealProductDto[]>(() => [])

  const products = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  const updatingId = ref<number | null>(null)
  const deletingId = ref<number | null>(null)

  const addMutation = useMutation<DealProductDto>()
  const updateMutation = useMutation<DealProductDto>()
  const deleteMutation = useMutation()

  async function load() {
    await resource.run(() => salesApi.getDealProducts(dealId()))
  }

  async function add(payload: {
    product_id: number
    plan_id?: number | null
    quantity: number
    unit_price?: number | null
  }): Promise<DealProductDto> {
    const item = await addMutation.run(() =>
      salesApi.addDealProduct(dealId(), payload),
    )
    resource.data.value = [...resource.data.value, item]
    return item
  }

  async function update(id: number, payload: { quantity?: number; unit_price?: number; discount?: number }) {
    updatingId.value = id
    try {
      const updated = await updateMutation.run(() =>
        salesApi.updateDealProduct(dealId(), id, payload),
      )
      resource.data.value = resource.data.value.map((p) => (p.id === id ? updated : p))
    } finally {
      updatingId.value = null
    }
  }

  async function remove(id: number) {
    deletingId.value = id
    try {
      await deleteMutation.run(() => salesApi.removeDealProduct(dealId(), id))
      resource.data.value = resource.data.value.filter((p) => p.id !== id)
    } finally {
      deletingId.value = null
    }
  }

  const totalAmount = computed(() =>
    products.value.reduce((sum, p) => sum + p.amount, 0),
  )

  return {
    products,
    loading,
    updatingId,
    deletingId,
    totalAmount,
    load,
    add,
    update,
    remove,
  }
}
