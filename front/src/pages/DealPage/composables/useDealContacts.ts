/**
 * Deal contacts composable — list, add, remove.
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import type { DealContactDto } from '@/entities/sales'

export function useDealContacts(dealId: () => number) {
  const resource = useAsyncResource<DealContactDto[]>(() => [])

  const contacts = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)
  const removingId = ref<number | null>(null)

  const addMutation = useMutation<DealContactDto>()
  const removeMutation = useMutation()

  async function load() {
    await resource.run(() => salesApi.getDealContacts(dealId()))
  }

  async function add(payload: { contact_id: number; is_primary: boolean }): Promise<DealContactDto> {
    const contact = await addMutation.run(() =>
      salesApi.addDealContact(dealId(), payload),
    )
    resource.data.value = [...resource.data.value, contact]
    return contact
  }

  async function remove(contactId: number) {
    removingId.value = contactId
    try {
      await removeMutation.run(() => salesApi.removeDealContact(dealId(), contactId))
      resource.data.value = resource.data.value.filter((c) => c.contact?.id !== contactId)
    } finally {
      removingId.value = null
    }
  }

  /** Directly replace the contacts list (e.g. after is_primary PATCH returns full list). */
  function setContacts(list: DealContactDto[]) {
    resource.data.value = list
  }

  return {
    contacts,
    loading,
    removingId,
    load,
    add,
    remove,
    setContacts,
  }
}
