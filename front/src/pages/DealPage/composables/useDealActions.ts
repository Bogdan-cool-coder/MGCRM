/**
 * Deal page actions — patch (no stage_id), delete.
 * Move stage is handled via MoveDealDialog (in page).
 */
import { ref, computed } from 'vue'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import type { DealDto, UpdateDealPayload } from '@/entities/sales'

export function useDealActions(
  dealId: () => number,
  onUpdated: (deal: DealDto) => void,
) {
  const patchMutation = useMutation<DealDto>()
  const deleteMutation = useMutation()

  const isSaving = computed(() => patchMutation.isPending.value)
  const isDeleting = computed(() => deleteMutation.isPending.value)

  // Move dialog state
  const moveDialogOpen = ref(false)

  async function patchField(field: string, value: unknown) {
    const payload: UpdateDealPayload = { [field]: value }
    const updated = await patchMutation.run(() => salesApi.updateDeal(dealId(), payload))
    onUpdated(updated)
  }

  async function deleteDeal(): Promise<void> {
    await deleteMutation.run(() => salesApi.deleteDeal(dealId()))
  }

  function openMoveDialog() {
    moveDialogOpen.value = true
  }

  function closeMoveDialog() {
    moveDialogOpen.value = false
  }

  return {
    isSaving,
    isDeleting,
    moveDialogOpen,
    patchField,
    deleteDeal,
    openMoveDialog,
    closeMoveDialog,
  }
}
